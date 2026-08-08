<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads the frozen playoff seed snapshot (`playoff_seeds`). The
 * presence of any rows for a Season *is* the "bracket exists" flag; the bracket
 * tree itself is not stored — it is derived from these seeds by standard
 * slotting (see PlayoffService).
 */
final class PlayoffRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasBracket(int $seasonId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM playoff_seeds WHERE season_id = ? LIMIT 1');
        $stmt->execute([$seasonId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Freeze the seed order. $orderedTeamIds[0] becomes seed 1, [1] seed 2, ….
     *
     * @param list<int> $orderedTeamIds
     */
    public function saveSeeds(int $leagueId, int $seasonId, array $orderedTeamIds): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO playoff_seeds (league_id, season_id, seed, team_id) VALUES (?, ?, ?, ?)'
        );
        $seed = 1;
        foreach ($orderedTeamIds as $teamId) {
            $stmt->execute([$leagueId, $seasonId, $seed, $teamId]);
            $seed++;
        }
    }

    /**
     * The frozen seeds as seed => team_id, ordered by seed (1..n).
     *
     * @return array<int,int>
     */
    public function seeds(int $seasonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT seed, team_id FROM playoff_seeds WHERE season_id = ? ORDER BY seed'
        );
        $stmt->execute([$seasonId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['seed']] = (int) $row['team_id'];
        }

        return $out;
    }

    public function clearSeeds(int $seasonId): void
    {
        $this->pdo->prepare('DELETE FROM playoff_seeds WHERE season_id = ?')->execute([$seasonId]);
    }
}
