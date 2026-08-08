<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads the Schedule of Matchups. Rows are written once at Draft
 * completion and cleared if the Draft reopens; scores/status are updated later
 * by the scoring and settlement services (Wave 3 slice 5+).
 */
final class MatchupRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<array{week:int,home_team_id:int,away_team_id:int}> $rows
     */
    public function insertMany(int $leagueId, int $seasonId, array $rows): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id)'
            . ' VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $stmt->execute([$leagueId, $seasonId, $r['week'], $r['home_team_id'], $r['away_team_id']]);
        }
    }

    public function clearForSeason(int $seasonId): void
    {
        $this->pdo->prepare('DELETE FROM matchups WHERE season_id = ?')->execute([$seasonId]);
    }

    /**
     * Insert one playoff round's Matchups (tagged with the round number) at a
     * given week. Rows are ordinary Matchups the Wave 3 pipeline scores unchanged.
     *
     * @param list<array{home_team_id:int,away_team_id:int}> $rows
     */
    public function insertPlayoffRound(int $leagueId, int $seasonId, int $week, int $round, array $rows): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, round, home_team_id, away_team_id)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($rows as $r) {
            $stmt->execute([$leagueId, $seasonId, $week, $round, $r['home_team_id'], $r['away_team_id']]);
        }
    }

    /**
     * The Matchups of a playoff round, ordered by id.
     *
     * @return list<array<string,mixed>>
     */
    public function forRound(int $seasonId, int $round): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM matchups WHERE season_id = ? AND round = ? ORDER BY id'
        );
        $stmt->execute([$seasonId, $round]);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forWeek(int $seasonId, int $week): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM matchups WHERE season_id = ? AND week = ? ORDER BY id'
        );
        $stmt->execute([$seasonId, $week]);

        return $stmt->fetchAll();
    }

    public function updateScores(int $matchupId, float $homeScore, float $awayScore, string $status): void
    {
        $this->pdo->prepare(
            'UPDATE matchups SET home_score = ?, away_score = ?, status = ? WHERE id = ?'
        )->execute([$homeScore, $awayScore, $status, $matchupId]);
    }

    public function settleWeek(int $seasonId, int $week): void
    {
        $this->pdo->prepare(
            "UPDATE matchups SET status = 'final' WHERE season_id = ? AND week = ?"
        )->execute([$seasonId, $week]);
    }
}
