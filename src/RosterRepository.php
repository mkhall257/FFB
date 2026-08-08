<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Persists and reads each Team's Season Roster. In Wave 2 the Roster is written
 * once, from the completed Draft board; later waves (add/drop, trades) will
 * mutate it.
 */
final class RosterRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Replace the Season's rosters with the completed Draft's picks.
     */
    public function materializeFromDraft(int $draftId, int $leagueId, int $seasonId): void
    {
        $this->clearForSeason($seasonId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO rosters (league_id, season_id, team_id, player_id, acquired)'
            . " SELECT ?, ?, team_id, player_id, 'draft' FROM draft_picks"
            . ' WHERE draft_id = ? AND player_id IS NOT NULL'
        );
        $stmt->execute([$leagueId, $seasonId, $draftId]);
    }

    public function clearForSeason(int $seasonId): void
    {
        $this->pdo->prepare('DELETE FROM rosters WHERE season_id = ?')->execute([$seasonId]);
    }

    /**
     * The Team currently rostering a Player this Season, or null when the Player
     * is a free agent (unrostered).
     */
    public function teamForPlayer(int $seasonId, string $playerId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT team_id FROM rosters WHERE season_id = ? AND player_id = ?'
        );
        $stmt->execute([$seasonId, $playerId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * The `acquired` value the Player currently carries on their Roster this
     * Season, or null when the Player is unrostered.
     */
    public function acquiredOf(int $seasonId, string $playerId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT acquired FROM rosters WHERE season_id = ? AND player_id = ?'
        );
        $stmt->execute([$seasonId, $playerId]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    /**
     * How many Players a Team currently rosters this Season.
     */
    public function sizeForTeam(int $seasonId, int $teamId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM rosters WHERE season_id = ? AND team_id = ?'
        );
        $stmt->execute([$seasonId, $teamId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Add a Player to a Team's Roster with the given acquisition. Relies on
     * uq_roster_player (season_id, player_id) to reject a Player another Team
     * already holds — the first-come-first-served backstop.
     */
    public function addPlayer(int $leagueId, int $seasonId, int $teamId, string $playerId, string $acquired): void
    {
        $this->pdo->prepare(
            'INSERT INTO rosters (league_id, season_id, team_id, player_id, acquired) VALUES (?,?,?,?,?)'
        )->execute([$leagueId, $seasonId, $teamId, $playerId, $acquired]);
    }

    /**
     * Remove a Player from whatever Roster holds them this Season (dropped to the
     * free-agent pool).
     */
    public function removePlayer(int $seasonId, string $playerId): void
    {
        $this->pdo->prepare(
            'DELETE FROM rosters WHERE season_id = ? AND player_id = ?'
        )->execute([$seasonId, $playerId]);
    }

    /**
     * Move a Player to a different Team and set how they were acquired there —
     * used when a Trade applies.
     */
    public function movePlayer(int $seasonId, string $playerId, int $toTeamId, string $acquired): void
    {
        $this->pdo->prepare(
            'UPDATE rosters SET team_id = ?, acquired = ? WHERE season_id = ? AND player_id = ?'
        )->execute([$toTeamId, $acquired, $seasonId, $playerId]);
    }

    /**
     * Rosters for a Season with Player details, grouped by team_id.
     *
     * @return array<int,list<array<string,mixed>>>
     */
    public function byTeam(int $seasonId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.team_id, r.player_id, p.full_name, p.position, p.nfl_team'
            . ' FROM rosters r JOIN players p ON p.sleeper_id = r.player_id'
            . ' WHERE r.season_id = ?'
            . " ORDER BY r.team_id, FIELD(p.position, 'QB','RB','WR','TE','K','DEF'), p.full_name"
        );
        $stmt->execute([$seasonId]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['team_id']][] = $row;
        }

        return $out;
    }
}
