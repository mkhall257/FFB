<?php

declare(strict_types=1);

/**
 * CLI: drive a full mock Season end to end, so the whole site can be exercised
 * before a live draft — auto-draft, scored weeks against the REAL Sleeper +
 * nflverse feeds, and a playoff bracket. It reuses the same repositories and
 * services the web app wires in {@see FFB\Kernel}, so it walks the real code
 * paths rather than poking the database by hand.
 *
 * Because the NFL offseason has no current-year stats, run the mock against a
 * COMPLETED season (default 2024) — both feeds have real, verified data there,
 * so scoring actually lights up.
 *
 * Typical flow (run from the project root, against a fresh local DB):
 *   php bin/mock-season.php setup   --teams=4 --season=2024 --regular-weeks=3
 *   php bin/mock-season.php draft
 *   php bin/mock-season.php week 1
 *   php bin/mock-season.php week 2
 *   php bin/mock-season.php week 3
 *   php bin/mock-season.php playoffs-create
 *   php bin/mock-season.php week 4          # championship round (regular-weeks + 1)
 *   php bin/mock-season.php playoffs-advance # only if >2 playoff teams
 *   php bin/mock-season.php status
 *
 * Or do the lot in one shot:
 *   php bin/mock-season.php full --teams=4 --season=2024 --regular-weeks=3
 *
 * Between any two commands you can log into the local site as the commissioner
 * or a manager and click around to verify what the last step produced.
 *
 * Notes / deliberate simplifications:
 *  - Managers are created as "manager1..N" with password "draft".
 *  - Each scored week auto-fills/carries-forward every Team's Lineup (the same
 *    logic the /lineup page uses), then runs LIVE scoring and OFFICIAL
 *    settlement. Pass --live-only to skip settlement.
 *  - The week's lineup-lock is set in the past so the week behaves as locked.
 *  - Re-running from scratch expects a fresh DB (drop + re-migrate); the draft
 *    step refuses to run over an already-started draft.
 */

use FFB\Database;
use FFB\DraftPickRepository;
use FFB\DraftQueueRepository;
use FFB\DraftRepository;
use FFB\Draft\AutoPickStrategy;
use FFB\Draft\DraftService;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\Lineup\LineupService;
use FFB\Lineup\WeekLock;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Playoffs\PlayoffService;
use FFB\PlayoffRepository;
use FFB\RosterRepository;
use FFB\Schedule\ScheduleGenerator;
use FFB\Schedule\ScheduleService;
use FFB\Scoring\MatchupScoringService;
use FFB\Scoring\NflverseStatsClient;
use FFB\Scoring\ScoringEngine;
use FFB\Scoring\SettlementService;
use FFB\Scoring\SleeperStatsClient;
use FFB\Scoring\StatsImporter;
use FFB\StandingsService;
use FFB\TeamRepository;
use FFB\UserRepository;

require __DIR__ . '/../vendor/autoload.php';

/* -------------------------------------------------------------------------- */
/* Argument parsing                                                           */
/* -------------------------------------------------------------------------- */

$argvRest = array_slice($argv, 1);
$command = $argvRest[0] ?? '';

$positionals = [];
$options = [];
foreach (array_slice($argvRest, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $eq = strpos($arg, '=');
        if ($eq === false) {
            $options[substr($arg, 2)] = true;
        } else {
            $options[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        }
    } else {
        $positionals[] = $arg;
    }
}

$opt = static fn (string $key, mixed $default): mixed => $options[$key] ?? $default;

/* -------------------------------------------------------------------------- */
/* Bootstrap — mirror Kernel's wiring for the subset we need                  */
/* -------------------------------------------------------------------------- */

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$leagues = new LeagueRepository($pdo);
$leagueId = $leagues->currentLeagueId();
$seasonId = $leagues->currentSeasonId();

$users = new UserRepository($pdo);
$teams = new TeamRepository($pdo);
$players = new PlayerRepository($pdo);
$settings = new LeagueSettingsRepository($pdo);
$drafts = new DraftRepository($pdo);
$draftPicks = new DraftPickRepository($pdo);
$draftQueues = new DraftQueueRepository($pdo);
$rosters = new RosterRepository($pdo);
$matchups = new MatchupRepository($pdo);
$lineupRepo = new LineupRepository($pdo);
$playoffRepo = new PlayoffRepository($pdo);
$statsRepo = new PlayerWeekStatsRepository($pdo);

$schedule = new ScheduleService(new ScheduleGenerator(), $matchups, $teams, $settings);
$lineupService = new LineupService($lineupRepo, $rosters, $settings, new WeekLock($settings));
$autoPick = new AutoPickStrategy($draftQueues, $draftPicks, $players);
$draftService = new DraftService($pdo, $drafts, $draftPicks, $players, $autoPick, $rosters, $settings, $leagues, $schedule);

$scoringEngine = new ScoringEngine();
$importer = new StatsImporter($statsRepo, $players);
$matchupScoring = new MatchupScoringService($matchups, $lineupRepo, $statsRepo, $scoringEngine, $settings);
$settlement = new SettlementService($importer, $matchupScoring, $matchups);
$playoffService = new PlayoffService(
    $pdo, $playoffRepo, new StandingsService($pdo), $settings, $teams, $matchups, $matchupScoring,
);

/* -------------------------------------------------------------------------- */
/* Small helpers                                                              */
/* -------------------------------------------------------------------------- */

/** Read a league setting as int, with a fallback. */
$settingInt = static function (string $key, int $default) use ($settings, $leagueId, $seasonId): int {
    $all = $settings->all($leagueId, $seasonId);

    return isset($all[$key]) && $all[$key] !== '' ? (int) $all[$key] : $default;
};

/** Total draft rounds = starter slots + bench, from the roster shape. */
$rosterRounds = static function () use ($settings, $leagueId, $seasonId): int {
    $all = $settings->all($leagueId, $seasonId);
    $slot = static fn (string $k): int => (int) ($all['roster.' . $k] ?? 0);

    return $slot('qb') + $slot('rb') + $slot('wr') + $slot('te')
        + $slot('flex') + $slot('k') + $slot('def') + $slot('bench');
};

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(1);
};

/**
 * Play one week: materialise every active Team's Lineup (same carry-forward the
 * /lineup page uses), then run LIVE scoring and, unless suppressed, OFFICIAL
 * settlement — pulling real stats from the live feeds for the stored season.
 */
$playWeek = static function (int $week, bool $settle) use (
    $settings, $leagues, $leagueId, $seasonId, $teams, $lineupService,
    $importer, $matchupScoring, $settlement, $statsRepo, $players, $matchups, $scoringEngine
): void {
    $all = $settings->all($leagueId, $seasonId);
    $season = (int) ($all['schedule.season_year'] ?? date('Y'));

    // Point the crons' shared settings at this week, with a lock time in the past.
    $settings->setMany($leagueId, $seasonId, [
        'schedule.season_year' => (string) $season,
        'schedule.current_week' => (string) $week,
        'schedule.week_' . $week . '_kickoff' => (new DateTimeImmutable('-1 hour'))->format('c'),
    ]);

    foreach ($teams->activeIdsForSeason($leagueId, $seasonId) as $teamId) {
        $lineupService->ensureLineup($leagueId, $seasonId, $week, (int) $teamId);
    }

    // LIVE (provisional) scoring from Sleeper.
    $lines = (new SleeperStatsClient())->fetchWeek($season, $week);
    $written = $importer->importSleeper($seasonId, $week, $lines);
    $matchupScoring->scoreWeek($leagueId, $seasonId, $week, 'live');
    echo "  week {$week}: live scored ({$written} Sleeper lines)\n";

    if ($settle) {
        $official = (new NflverseStatsClient())->fetchWeek($season, $week);
        $settlement->settleWeek($leagueId, $seasonId, $week, $official);
        echo "  week {$week}: settled official (" . count($official) . " nflverse lines) — week locked\n";
    }
};

/* -------------------------------------------------------------------------- */
/* Commands                                                                   */
/* -------------------------------------------------------------------------- */

switch ($command) {
    case 'setup': {
        $teamCount = (int) $opt('teams', 4);
        $season = (int) $opt('season', 2024);
        $regularWeeks = (int) $opt('regular-weeks', 3);
        if ($teamCount < 2) {
            $fail('Need at least 2 teams (--teams=N).');
        }

        $settings->setMany($leagueId, $seasonId, [
            'schedule.season_year' => (string) $season,
            'schedule.regular_season_weeks' => (string) $regularWeeks,
        ]);

        // Guarantee a legal roster shape so the draft has rounds to fill.
        if ($rosterRounds() < 1) {
            $settings->setMany($leagueId, $seasonId, [
                'roster.qb' => '1', 'roster.rb' => '2', 'roster.wr' => '2', 'roster.te' => '1',
                'roster.flex' => '1', 'roster.k' => '1', 'roster.def' => '1', 'roster.bench' => '5',
            ]);
            echo "Seeded a default roster shape.\n";
        }

        $existing = $teams->listWithManagers($leagueId, $seasonId);
        if ($existing !== []) {
            $fail('Teams already exist for this season; drop + re-migrate the DB for a clean mock.');
        }

        for ($i = 1; $i <= $teamCount; $i++) {
            $username = "manager{$i}";
            $userId = $users->create($leagueId, $username, 'draft', 'manager', "Manager {$i}");
            $teamId = $teams->create($leagueId, $seasonId, "Team {$i}");
            $teams->assignManager($teamId, $userId);
            echo "  created Team {$i} + login '{$username}' (password: draft)\n";
        }

        echo "Setup done: {$teamCount} teams, season {$season}, {$regularWeeks}-week regular season.\n";
        echo "Next: php bin/mock-season.php draft\n";
        break;
    }

    case 'draft': {
        $draft = $drafts->currentOrCreate($leagueId, $seasonId);
        if ($draft['state'] !== 'setup') {
            $fail("Draft is already '{$draft['state']}'. Drop + re-migrate the DB to re-run the mock draft.");
        }

        $rounds = $rosterRounds();
        if ($rounds < 1) {
            $fail('No roster shape set — run "setup" first.');
        }

        $order = $teams->activeIdsForSeason($leagueId, $seasonId);
        if (count($order) < 2) {
            $fail('Need at least 2 active teams — run "setup" first.');
        }
        shuffle($order);

        $pickSeconds = (int) $opt('pick-seconds', 60);
        $drafts->updateConfig((int) $draft['id'], $pickSeconds, true, null);
        $drafts->setOrder((int) $draft['id'], $order);
        $drafts->setState((int) $draft['id'], 'ready');

        $pdo->beginTransaction();
        try {
            $draftPicks->generateBoard((int) $draft['id'], $order, $rounds);
            $drafts->start((int) $draft['id'], $pickSeconds);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Put every team in Auto-draft, then let the engine run the whole board.
        foreach ($order as $teamId) {
            $drafts->setAutoDraft((int) $draft['id'], (int) $teamId, true);
        }
        $draftService->runAutoDrafts();

        $fresh = $drafts->find($leagueId, $seasonId);
        $state = $fresh['state'] ?? 'unknown';
        if ($state !== 'complete') {
            $fail("Auto-draft stopped in state '{$state}' — check the board at /admin/draft.");
        }

        echo "Draft complete ({$rounds} rounds x " . count($order) . " teams). "
            . "Rosters materialised and the schedule was generated.\n";
        echo "Next: php bin/mock-season.php week 1\n";
        break;
    }

    case 'week': {
        $week = (int) ($positionals[0] ?? 0);
        if ($week < 1) {
            $fail('Usage: php bin/mock-season.php week <N> [--live-only]');
        }
        $settle = $opt('live-only', false) === false;
        echo "Playing week {$week}...\n";
        $playWeek($week, $settle);
        echo "Done. Check /scoreboard and /standings.\n";
        break;
    }

    case 'playoffs-create': {
        $playoffService->create($leagueId, $seasonId, (new DateTimeImmutable('-1 hour'))->format('c'));
        $regularWeeks = $settingInt('schedule.regular_season_weeks', 14);
        $firstPlayoffWeek = $regularWeeks + 1;
        echo "Playoff bracket created. Round 1 lives in week {$firstPlayoffWeek}.\n";
        echo "Next: php bin/mock-season.php week {$firstPlayoffWeek}\n";
        break;
    }

    case 'playoffs-advance': {
        try {
            $playoffService->advance($leagueId, $seasonId, (new DateTimeImmutable('-1 hour'))->format('c'));
        } catch (\FFB\Playoffs\PlayoffException $e) {
            // A "no next round" here is the normal end of the bracket, not a crash.
            $fail($e->getMessage());
        }
        echo "Advanced to the next playoff round. Score its week next, then advance again if rounds remain.\n";
        break;
    }

    case 'full': {
        $teamCount = (int) $opt('teams', 4);
        $season = (int) $opt('season', 2024);
        $regularWeeks = (int) $opt('regular-weeks', 3);

        echo "=== SETUP ===\n";
        passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__)
            . " setup --teams={$teamCount} --season={$season} --regular-weeks={$regularWeeks}", $rc);
        if ($rc !== 0) { exit($rc); }

        echo "\n=== DRAFT ===\n";
        passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' draft', $rc);
        if ($rc !== 0) { exit($rc); }

        for ($w = 1; $w <= $regularWeeks; $w++) {
            echo "\n=== WEEK {$w} ===\n";
            passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . " week {$w}", $rc);
            if ($rc !== 0) { exit($rc); }
        }

        echo "\n=== PLAYOFFS: CREATE ===\n";
        passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' playoffs-create', $rc);
        if ($rc !== 0) { exit($rc); }

        // Play + advance through however many rounds the bracket needs.
        $round = 1;
        $week = $regularWeeks + 1;
        while (true) {
            echo "\n=== PLAYOFF WEEK {$week} (round {$round}) ===\n";
            passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . " week {$week}", $rc);
            if ($rc !== 0) { exit($rc); }

            // Try to advance; a non-zero here means the bracket is decided.
            echo "\n=== PLAYOFFS: ADVANCE (after round {$round}) ===\n";
            passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' playoffs-advance', $rc);
            if ($rc !== 0) {
                echo "No further rounds — the champion is decided.\n";
                break;
            }
            $round++;
            $week++;
        }

        echo "\n=== FULL MOCK COMPLETE ===\n";
        passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' status');
        break;
    }

    case 'status': {
        $all = $settings->all($leagueId, $seasonId);
        $season = (int) ($all['schedule.season_year'] ?? 0);
        $week = (int) ($all['schedule.current_week'] ?? 0);
        $regularWeeks = (int) ($all['schedule.regular_season_weeks'] ?? 14);
        $draft = $drafts->find($leagueId, $seasonId);

        echo "Season year:        {$season}\n";
        echo "Current week:        {$week}\n";
        echo "Regular-season wks:  {$regularWeeks}\n";
        echo "Draft state:         " . ($draft['state'] ?? 'none') . "\n";
        echo "Playoff bracket:     " . ($playoffRepo->hasBracket($seasonId) ? 'yes' : 'no') . "\n";

        echo "\nStandings:\n";
        $names = $teams->namesForSeason($leagueId, $seasonId);
        $rows = (new StandingsService($pdo))->compute($seasonId);
        if ($rows === []) {
            echo "  (no final matchups yet)\n";
        }
        foreach ($rows as $row) {
            $name = $names[$row['team_id']] ?? ('Team ' . $row['team_id']);
            echo sprintf(
                "  %-20s %d-%d-%d  PF %.1f\n",
                $name, $row['wins'], $row['losses'], $row['ties'], (float) $row['points_for'],
            );
        }
        break;
    }

    default:
        echo "FFB mock-season driver\n\n";
        echo "Commands:\n";
        echo "  setup   --teams=4 --season=2024 --regular-weeks=3\n";
        echo "  draft\n";
        echo "  week <N> [--live-only]\n";
        echo "  playoffs-create\n";
        echo "  playoffs-advance\n";
        echo "  full    --teams=4 --season=2024 --regular-weeks=3\n";
        echo "  status\n";
        if ($command !== '' && $command !== 'help') {
            exit(1);
        }
}
