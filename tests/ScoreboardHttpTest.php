<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class ScoreboardHttpTest extends DatabaseTestCase
{
    public function testScoreboardLabelsLiveState(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();
        (new LeagueSettingsRepository($this->pdo))->setMany($leagueId, $seasonId, ['schedule.current_week' => '1']);
        $h = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Home');
        $a = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Away');
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id, home_score, away_score, status)'
            . " VALUES (?,?,1,?,?,50,40,'live')"
        )->execute([$leagueId, $seasonId, $h, $a]);

        $session = new ArraySession([
            'user_id' => 1, 'role' => 'manager', 'league_id' => $leagueId, 'display_name' => 'M',
        ]);
        $response = Kernel::router($this->pdo)->dispatch(new Request('GET', '/scoreboard'), $session);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Live', $response->body);
        $this->assertStringContainsString('Home', $response->body);
        $this->assertStringContainsString('50.00', $response->body);
    }

    public function testWeekOverrideViaQuery(): void
    {
        $leagues = new LeagueRepository($this->pdo);
        $leagueId = $leagues->currentLeagueId();
        $seasonId = $leagues->currentSeasonId();
        $h = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Home');
        $a = (new TeamRepository($this->pdo))->create($leagueId, $seasonId, 'Away');
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id, status)'
            . " VALUES (?,?,5,?,?,'scheduled')"
        )->execute([$leagueId, $seasonId, $h, $a]);

        $session = new ArraySession([
            'user_id' => 1, 'role' => 'manager', 'league_id' => $leagueId, 'display_name' => 'M',
        ]);
        $response = Kernel::router($this->pdo)->dispatch(
            new Request('GET', '/scoreboard', [], ['week' => '5']),
            $session,
        );

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Week 5', $response->body);
        $this->assertStringContainsString('Scheduled', $response->body);
    }
}
