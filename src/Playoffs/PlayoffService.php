<?php

declare(strict_types=1);

namespace FFB\Playoffs;

use FFB\LeagueSettingsRepository;
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
    ) {
    }

    /**
     * Create the bracket: validate the field size, confirm the regular season is
     * settled, then freeze the top-n Standings order as the seeds. Idempotent
     * only in the sense that a second call is refused — use reset to redo.
     *
     * @throws PlayoffException
     */
    public function create(int $leagueId, int $seasonId): void
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
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
