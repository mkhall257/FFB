<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class LineupSchemaTest extends DatabaseTestCase
{
    public function testASlotIsUniquePerTeamWeek(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();
        $team = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'T1');
        $players = new PlayerRepository($this->pdo);
        $players->upsert('P1', null, 'P One', 'RB', 'KC', 'Active', 1);
        $players->upsert('P2', null, 'P Two', 'RB', 'KC', 'Active', 2);

        $sql = 'INSERT INTO lineups (league_id, season_id, week, team_id, roster_slot, slot_index, player_id)'
             . ' VALUES (?, ?, 1, ?, ?, ?, ?)';
        $this->pdo->prepare($sql)->execute([$leagueId, $seasonId, $team, 'RB', 0, 'P1']);

        $this->expectException(\PDOException::class);
        $this->pdo->prepare($sql)->execute([$leagueId, $seasonId, $team, 'RB', 0, 'P2']); // same slot -> unique violation
    }
}
