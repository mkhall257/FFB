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
     * Activate or deactivate a Team. Scoped to League + Season as a safety check.
     */
    public function setActive(int $leagueId, int $seasonId, int $teamId, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE teams SET is_active = ? WHERE id = ? AND league_id = ? AND season_id = ?'
        );
        $stmt->execute([$active ? 1 : 0, $teamId, $leagueId, $seasonId]);
    }

    /**
     * Permanently remove a Team. The caller MUST first confirm isDeletable() —
     * a Team referenced by any history row cannot be deleted (FKs have no
     * cascade). Scoped to League + Season as a safety check.
     */
    public function delete(int $leagueId, int $seasonId, int $teamId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM teams WHERE id = ? AND league_id = ? AND season_id = ?'
        );
        $stmt->execute([$teamId, $leagueId, $seasonId]);
    }

    /**
     * Whether a Team can be hard-deleted: true only when no history row in any
     * table references it. A Team with a roster, matchup, lineup, draft, trade,
     * or playoff record must be deactivated instead.
     */
    public function isDeletable(int $teamId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT'
            . ' (SELECT COUNT(*) FROM rosters WHERE team_id = ?)'
            . ' + (SELECT COUNT(*) FROM matchups WHERE home_team_id = ? OR away_team_id = ?)'
            . ' + (SELECT COUNT(*) FROM lineups WHERE team_id = ?)'
            . ' + (SELECT COUNT(*) FROM draft_order WHERE team_id = ?)'
            . ' + (SELECT COUNT(*) FROM draft_picks WHERE team_id = ?)'
            . ' + (SELECT COUNT(*) FROM draft_queue WHERE team_id = ?)'
            . ' + (SELECT COUNT(*) FROM transaction_items WHERE from_team_id = ? OR to_team_id = ?)'
            . ' + (SELECT COUNT(*) FROM transactions WHERE proposed_by_team = ? OR accepted_by_team = ?)'
            . ' + (SELECT COUNT(*) FROM playoff_seeds WHERE team_id = ?) AS refs'
        );
        $stmt->execute(array_fill(0, 12, $teamId));

        return (int) $stmt->fetchColumn() === 0;
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
     * Active Team ids for a Season, ordered by id. Excludes deactivated Teams —
     * used everywhere Teams are enrolled into play (Draft order, schedule
     * generation, Playoff seeding).
     *
     * @return list<int>
     */
    public function activeIdsForSeason(int $leagueId, int $seasonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM teams WHERE league_id = ? AND season_id = ? AND is_active = 1 ORDER BY id'
        );
        $stmt->execute([$leagueId, $seasonId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * team_id => team name for a Season.
     *
     * @return array<int,string>
     */
    public function namesForSeason(int $leagueId, int $seasonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM teams WHERE league_id = ? AND season_id = ?'
        );
        $stmt->execute([$leagueId, $seasonId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * Every Team with its Manager (if assigned), ordered by team name.
     *
     * @return list<array<string,mixed>>
     */
    public function listWithManagers(int $leagueId, int $seasonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id AS team_id, t.name AS team_name, t.user_id, t.is_active AS team_active,'
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
