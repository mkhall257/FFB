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
