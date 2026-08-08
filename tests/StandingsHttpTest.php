<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class StandingsHttpTest extends DatabaseTestCase
{
    public function testStandingsPageListsTeamsInSeedOrder(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();
        $winner = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Winners');
        $loser = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Losers');
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id, home_score, away_score, status)'
            . " VALUES (?,?,1,?,?,120,80,'final')"
        )->execute([$leagueId, $seasonId, $winner, $loser]);

        $session = new ArraySession([
            'user_id' => 1, 'role' => 'manager', 'league_id' => $leagueId, 'display_name' => 'M',
        ]);
        $response = Kernel::router($this->pdo)->dispatch(new Request('GET', '/standings'), $session);

        $this->assertSame(200, $response->status);
        $body = $response->body;
        $this->assertStringContainsString('Winners', $body);
        // Winners appear before Losers in the rendered table.
        $this->assertLessThan(strpos($body, 'Losers'), strpos($body, 'Winners'));
    }
}
