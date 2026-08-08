<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class MatchupSchemaTest extends DatabaseTestCase
{
    public function testMatchupsTableAcceptsAScheduledRow(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();
        $teams = new TeamRepository($this->pdo);
        $home = $teams->create($leagueId, $seasonId, 'Home');
        $away = $teams->create($leagueId, $seasonId, 'Away');

        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id)'
            . ' VALUES (?, ?, 1, ?, ?)'
        )->execute([$leagueId, $seasonId, $home, $away]);

        $row = $this->pdo->query('SELECT status, home_score FROM matchups')->fetch();

        $this->assertSame('scheduled', $row['status']);
        $this->assertNull($row['home_score']);
    }
}
