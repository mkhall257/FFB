<?php

declare(strict_types=1);

namespace FFB\Playoffs;

use FFB\LeagueSettingsRepository;
use FFB\MatchupRepository;
use FFB\PlayoffRepository;
use FFB\StandingsService;
use FFB\TeamRepository;
use PDO;

/**
 * Owns the Playoff bracket. Creating a bracket freezes the current Standings
 * order into the top `playoffs.team_count` seeds; later rounds are derived from
 * those frozen seeds by standard slotting (Wave 5 slice 3+). Playoff games are
 * ordinary `matchups` rows tagged with a round, scored by the existing Wave 3
 * pipeline — this service never reimplements scoring.
 */
final class PlayoffService
{
    private const DEFAULT_REGULAR_WEEKS = 14;
    private const DEFAULT_TEAM_COUNT = 4;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PlayoffRepository $playoffs,
        private readonly StandingsService $standings,
        private readonly LeagueSettingsRepository $settings,
        private readonly TeamRepository $teams,
        private readonly MatchupRepository $matchups,
    ) {
    }

    /**
     * Create the bracket: validate the field size, confirm the regular season is
     * settled, then freeze the top-n Standings order as the seeds. Idempotent
     * only in the sense that a second call is refused — use reset to redo.
     *
     * @throws PlayoffException
     */
    public function create(int $leagueId, int $seasonId, ?string $kickoffIso = null): void
    {
        if ($this->playoffs->hasBracket($seasonId)) {
            throw new PlayoffException(409, 'The playoff bracket has already been created.');
        }

        $settings = $this->settings->all($leagueId, $seasonId);
        $regularWeeks = (int) ($settings['schedule.regular_season_weeks'] ?? self::DEFAULT_REGULAR_WEEKS);
        $teamCount = (int) ($settings['playoffs.team_count'] ?? self::DEFAULT_TEAM_COUNT);

        if (!$this->regularSeasonSettled($seasonId, $regularWeeks)) {
            throw new PlayoffException(
                409,
                "The regular season isn't finished yet — every week {$regularWeeks} matchup must be final before seeding the playoffs.",
            );
        }

        $seedOrder = $this->seedOrder($leagueId, $seasonId);

        if ($teamCount < 2) {
            throw new PlayoffException(422, 'At least 2 teams must make the playoffs.');
        }
        if ($teamCount > count($seedOrder)) {
            throw new PlayoffException(
                422,
                "Can't seed {$teamCount} teams — the league only has " . count($seedOrder) . '.',
            );
        }

        $qualifiers = array_slice($seedOrder, 0, $teamCount);

        $this->pdo->beginTransaction();
        try {
            $this->playoffs->saveSeeds($leagueId, $seasonId, $qualifiers);
            $rows = [];
            foreach (Bracket::firstRoundGames(count($qualifiers)) as $game) {
                $rows[] = [
                    'home_team_id' => $qualifiers[$game['high'] - 1],
                    'away_team_id' => $qualifiers[$game['low'] - 1],
                ];
            }
            $this->openRound($leagueId, $seasonId, $regularWeeks + 1, 1, $rows, $kickoffIso);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Advance the bracket: confirm the current round is fully final, work out who
     * advanced (higher final score; a tie falls to the higher seed — the per-
     * starter tiebreak lands in a later slice), and open the next round by pairing
     * those survivors into the next tree slots. Reuses the same week-opening
     * mechanic as "Start a week".
     *
     * @throws PlayoffException
     */
    public function advance(int $leagueId, int $seasonId, ?string $kickoffIso = null): void
    {
        if (!$this->playoffs->hasBracket($seasonId)) {
            throw new PlayoffException(409, 'Create the playoff bracket first.');
        }

        $seeds = $this->playoffs->seeds($seasonId);
        $fieldSize = count($seeds);
        $totalRounds = Bracket::roundCount($fieldSize);
        $current = $this->currentRound($seasonId);

        if ($current >= $totalRounds) {
            throw new PlayoffException(409, 'The playoffs are already decided — there is no next round.');
        }
        foreach ($this->matchups->forRound($seasonId, $current) as $m) {
            if ((string) $m['status'] !== 'final') {
                throw new PlayoffException(409, "Round {$current} isn't finished yet — every game must be final before advancing.");
            }
        }

        $advancers = $this->advancersOutOf($seasonId, $current, $seeds);
        $rows = [];
        for ($i = 0; $i < count($advancers); $i += 2) {
            $rows[] = ['home_team_id' => $advancers[$i], 'away_team_id' => $advancers[$i + 1]];
        }

        $nextRound = $current + 1;
        $regularWeeks = (int) ($this->settings->all($leagueId, $seasonId)['schedule.regular_season_weeks'] ?? self::DEFAULT_REGULAR_WEEKS);

        $this->pdo->beginTransaction();
        try {
            $this->openRound($leagueId, $seasonId, $regularWeeks + $nextRound, $nextRound, $rows, $kickoffIso);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The ordered list of Teams advancing OUT of a round, in bracket-slot order so
     * that pairing them consecutively yields the next round. Round 1 interleaves
     * bye seeds (who advance automatically) with the winners of the first-round
     * games; later rounds are simply their winners in order.
     *
     * @param array<int,int> $seeds seed => team_id
     * @return list<int>
     */
    private function advancersOutOf(int $seasonId, int $round, array $seeds): array
    {
        $teamSeed = array_flip($seeds);

        if ($round > 1) {
            $out = [];
            foreach ($this->matchups->forRound($seasonId, $round) as $m) {
                $out[] = $this->winnerOf($m, $teamSeed);
            }

            return $out;
        }

        $games = $this->matchups->forRound($seasonId, 1);
        $gameIndex = 0;
        $out = [];
        foreach (Bracket::firstRoundPairings(count($seeds)) as [$high, $low]) {
            if ($low > count($seeds)) {
                $out[] = $seeds[$high]; // bye: the higher seed advances automatically
            } else {
                $out[] = $this->winnerOf($games[$gameIndex++], $teamSeed);
            }
        }

        return $out;
    }

    /**
     * The Team that won a settled playoff Matchup: higher final score, and on an
     * exact tie the higher seed (lower seed number). A later slice inserts the
     * per-starter comparison ahead of the seed backstop.
     *
     * @param array<string,mixed> $m
     * @param array<int,int> $teamSeed team_id => seed
     */
    private function winnerOf(array $m, array $teamSeed): int
    {
        $home = (int) $m['home_team_id'];
        $away = (int) $m['away_team_id'];
        $homeScore = (float) $m['home_score'];
        $awayScore = (float) $m['away_score'];

        if ($homeScore > $awayScore) {
            return $home;
        }
        if ($awayScore > $homeScore) {
            return $away;
        }

        return ($teamSeed[$home] ?? PHP_INT_MAX) < ($teamSeed[$away] ?? PHP_INT_MAX) ? $home : $away;
    }

    /** The highest playoff round that has been opened, or 0 if none. */
    private function currentRound(int $seasonId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(round) FROM matchups WHERE season_id = ? AND round IS NOT NULL'
        );
        $stmt->execute([$seasonId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Open a playoff round: write its Matchups, make its week current, and (when a
     * kickoff is given) stamp that week's lineup-lock time — the same mechanic as
     * "Start a week".
     *
     * @param list<array{home_team_id:int,away_team_id:int}> $rows
     */
    private function openRound(int $leagueId, int $seasonId, int $week, int $round, array $rows, ?string $kickoffIso): void
    {
        $this->matchups->insertPlayoffRound($leagueId, $seasonId, $week, $round, $rows);

        $updates = ['schedule.current_week' => (string) $week];
        if ($kickoffIso !== null && $kickoffIso !== '') {
            $updates['schedule.week_' . $week . '_kickoff'] = $kickoffIso;
        }
        $this->settings->setMany($leagueId, $seasonId, $updates);
    }

    /**
     * The full Team set ordered as it seeds the playoffs: Standings order first
     * (ADR-0009: win% → points-for → team id), with any Team missing from the
     * Standings appended by id so the order always covers every Team.
     *
     * @return list<int>
     */
    private function seedOrder(int $leagueId, int $seasonId): array
    {
        $order = [];
        foreach ($this->standings->compute($seasonId) as $row) {
            $order[] = (int) $row['team_id'];
        }

        foreach ($this->teams->idsForSeason($leagueId, $seasonId) as $teamId) {
            if (!in_array($teamId, $order, true)) {
                $order[] = $teamId;
            }
        }

        return $order;
    }

    /**
     * The regular season is settled when the final regular-season week has at
     * least one Matchup and every one of that week's regular-season Matchups
     * (round IS NULL) is final.
     */
    private function regularSeasonSettled(int $seasonId, int $regularWeeks): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total, SUM(status = 'final') AS finals"
            . ' FROM matchups WHERE season_id = ? AND week = ? AND round IS NULL'
        );
        $stmt->execute([$seasonId, $regularWeeks]);
        $row = $stmt->fetch();

        $total = (int) ($row['total'] ?? 0);
        $finals = (int) ($row['finals'] ?? 0);

        return $total > 0 && $total === $finals;
    }
}
