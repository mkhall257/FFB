<?php

declare(strict_types=1);

/**
 * Official settlement — run daily (ICDSoft cron), a day or two after a week's
 * games. Ingests nflverse official stats for the target week, rescores Matchups
 * as final, and locks the week (ADR-0005). May change a result; Standings then
 * reflect the settled outcome.
 *
 * Settles every played week (before schedule.current_week) that is not yet
 * final, oldest first — so a missed or failed run catches up on its next run
 * instead of leaving that week unsettled forever. A week nflverse has not
 * published yet is skipped and retried tomorrow. Setting schedule.settle_week
 * settles (or re-settles) just that one week instead.
 *
 * Usage:
 *   php cron/settle_official.php
 */

use FFB\Database;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Scoring\MatchupScoringService;
use FFB\Scoring\NflverseStatsClient;
use FFB\Scoring\ScoringEngine;
use FFB\Scoring\SettlementService;
use FFB\Scoring\StatsImporter;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$leagues = new LeagueRepository($pdo);
$leagueId = $leagues->currentLeagueId();
$seasonId = $leagues->currentSeasonId();

$settings = new LeagueSettingsRepository($pdo);
$all = $settings->all($leagueId, $seasonId);
$season = (int) ($all['schedule.season_year'] ?? date('Y'));
$matchups = new MatchupRepository($pdo);
$override = trim((string) ($all['schedule.settle_week'] ?? ''));
$weeks = $override !== ''
    ? [(int) $override]
    : $matchups->unsettledWeeksBefore($seasonId, (int) ($all['schedule.current_week'] ?? 0));
$weeks = array_values(array_filter($weeks, static fn (int $w): bool => $w >= 1));
if ($weeks === []) {
    echo "No played weeks waiting to settle.\n";
    exit(0);
}

try {
    $stats = new PlayerWeekStatsRepository($pdo);
    $importer = new StatsImporter($stats, new PlayerRepository($pdo));
    $scoring = new MatchupScoringService(
        $matchups,
        new LineupRepository($pdo),
        $stats,
        new ScoringEngine(),
        $settings,
    );
    $settlement = new SettlementService($importer, $scoring, $matchups);

    $byWeek = (new NflverseStatsClient())->fetchWeeks($season, $weeks);
} catch (\Throwable $e) {
    fwrite(STDERR, "Fetching official stats failed: {$e->getMessage()}\n");
    exit(1);
}

$failed = false;
foreach ($byWeek as $week => $lines) {
    if ($lines === []) {
        echo "Week {$week}: nflverse has not published official stats yet; will retry next run.\n";
        continue;
    }
    try {
        $settlement->settleWeek($leagueId, $seasonId, $week, $lines);
        echo "Settled week {$week} to official (" . count($lines) . " official stat lines).\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "Settling week {$week} failed: {$e->getMessage()}\n");
        $failed = true;
    }
}

exit($failed ? 1 : 0);
