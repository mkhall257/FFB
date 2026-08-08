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
