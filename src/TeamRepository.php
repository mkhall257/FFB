<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads Teams and their managing Manager, scoped to a
 * League and Season.
 */
final class TeamRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(int $leagueId, int $seasonId, string $name): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO teams (league_id, season_id, name) VALUES (?, ?, ?)'
        );
        $stmt->execute([$leagueId, $seasonId, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    public function assignManager(int $teamId, int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE teams SET user_id = ? WHERE id = ?');
        $stmt->execute([$userId, $teamId]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $leagueId, int $seasonId, int $teamId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM teams WHERE id = ? AND league_id = ? AND season_id = ?'
        );
        $stmt->execute([$teamId, $leagueId, $seasonId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The Team managed by a given user in a Season, or null.
     *
     * @return array<string,mixed>|null
     */
    public function findByUser(int $leagueId, int $seasonId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM teams WHERE league_id = ? AND season_id = ? AND user_id = ?'
        );
        $stmt->execute([$leagueId, $seasonId, $userId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The Team ids for a Season, ordered by id.
     *
     * @return list<int>
     */
    public function idsForSeason(int $leagueId, int $seasonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM teams WHERE league_id = ? AND season_id = ? ORDER BY id'
        );
        $stmt->execute([$leagueId, $seasonId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Every Team with its Manager (if assigned), ordered by team name.
     *
     * @return list<array<string,mixed>>
     */
    public function listWithManagers(int $leagueId, int $seasonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id AS team_id, t.name AS team_name, t.user_id,'
            . ' u.username, u.display_name, u.is_active'
            . ' FROM teams t'
            . ' LEFT JOIN users u ON u.id = t.user_id'
            . ' WHERE t.league_id = ? AND t.season_id = ?'
            . ' ORDER BY t.name'
        );
        $stmt->execute([$leagueId, $seasonId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();

        return $rows;
    }
}
