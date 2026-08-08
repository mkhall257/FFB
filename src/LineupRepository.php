<?php

declare(strict_types=1);

namespace FFB;

use PDO;

/**
 * Reads and writes weekly Lineup slot assignments. Bench = rostered Players with
 * no row here for the week.
 */
final class LineupRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{roster_slot:string,slot_index:int,player_id:?string}>
     */
    public function forTeamWeek(int $seasonId, int $week, int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT roster_slot, slot_index, player_id FROM lineups'
            . ' WHERE season_id = ? AND week = ? AND team_id = ?'
            . " ORDER BY FIELD(roster_slot,'QB','RB','WR','TE','FLEX','K','DEF'), slot_index"
        );
        $stmt->execute([$seasonId, $week, $teamId]);

        return array_map(static fn ($r) => [
            'roster_slot' => (string) $r['roster_slot'],
            'slot_index' => (int) $r['slot_index'],
            'player_id' => $r['player_id'] !== null ? (string) $r['player_id'] : null,
        ], $stmt->fetchAll());
    }

    /**
     * @param list<array{roster_slot:string,slot_index:int,player_id:?string}> $slots
     */
    public function replaceForTeamWeek(int $leagueId, int $seasonId, int $week, int $teamId, array $slots): void
    {
        $this->pdo->prepare(
            'DELETE FROM lineups WHERE season_id = ? AND week = ? AND team_id = ?'
        )->execute([$seasonId, $week, $teamId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO lineups (league_id, season_id, week, team_id, roster_slot, slot_index, player_id)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($slots as $s) {
            $stmt->execute([$leagueId, $seasonId, $week, $teamId, $s['roster_slot'], $s['slot_index'], $s['player_id']]);
        }
    }

    /**
     * team_id => started (non-null) players for the week.
     *
     * @return array<int, list<array{roster_slot:string,player_id:string}>>
     */
    public function startersForWeek(int $seasonId, int $week): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT team_id, roster_slot, player_id FROM lineups'
            . ' WHERE season_id = ? AND week = ? AND player_id IS NOT NULL'
        );
        $stmt->execute([$seasonId, $week]);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['team_id']][] = [
                'roster_slot' => (string) $r['roster_slot'],
                'player_id' => (string) $r['player_id'],
            ];
        }

        return $out;
    }
}
