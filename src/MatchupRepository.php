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
}
