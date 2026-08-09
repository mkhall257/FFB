<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\StandingsService;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class StandingsServiceTest extends DatabaseTestCase
{
    private int $leagueId;
    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
    }

    private function team(string $name): int
    {
        return (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, $name);
    }

    private function finalMatchup(int $week, int $home, int $away, float $hs, float $as): void
    {
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id, home_score, away_score, status)'
            . " VALUES (?,?,?,?,?,?,?,'final')"
        )->execute([$this->leagueId, $this->seasonId, $week, $home, $away, $hs, $as]);
    }

    public function testRanksByRecordThenPointsFor(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');
        $c = $this->team('C');

        // A beats B (final); C ties A (final).
        $this->finalMatchup(1, $a, $b, 100, 90);
        $this->finalMatchup(2, $a, $c, 80, 80);

        $rows = (new StandingsService($this->pdo))->compute($this->seasonId);

        // A: 1-0-1 win_pct .75, pf 180; C: 0-0-1 .5, pf 80; B: 0-1-0 0, pf 90.
        $this->assertSame($a, $rows[0]['team_id']);
        $this->assertSame(1, $rows[0]['wins']);
        $this->assertSame(1, $rows[0]['ties']);
        $this->assertSame(180.0, $rows[0]['points_for']);
        $this->assertSame($c, $rows[1]['team_id']); // .5 beats B's 0
        $this->assertSame($b, $rows[2]['team_id']);
    }

    public function testPointsForBreaksAWinTie(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');
        $c = $this->team('C');
        $d = $this->team('D');

        // A and B each go 1-0, but A scored more.
        $this->finalMatchup(1, $a, $c, 120, 50);
        $this->finalMatchup(1, $b, $d, 100, 50);

        $rows = (new StandingsService($this->pdo))->compute($this->seasonId);
        $this->assertSame($a, $rows[0]['team_id']);
        $this->assertSame($b, $rows[1]['team_id']);
    }

    public function testPlayoffGamesDoNotCountTowardStandings(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');

        // Regular-season result (round IS NULL) counts.
        $this->finalMatchup(1, $a, $b, 100, 90);

        // A final PLAYOFF game (round set) must not touch regular-season standings —
        // standings freeze at the end of the regular season; the bracket is separate.
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, round, home_team_id, away_team_id, home_score, away_score, status)'
            . " VALUES (?,?,?,1,?,?,?,?,'final')"
        )->execute([$this->leagueId, $this->seasonId, 4, $b, $a, 130, 60]);

        $rows = (new StandingsService($this->pdo))->compute($this->seasonId);

        // A stays 1-0 (the playoff loss is ignored); B stays 0-1 (the playoff win is ignored).
        $this->assertSame($a, $rows[0]['team_id']);
        $this->assertSame(1, $rows[0]['wins']);
        $this->assertSame(0, $rows[0]['losses']);
        $this->assertSame($b, $rows[1]['team_id']);
        $this->assertSame(0, $rows[1]['wins']);
        $this->assertSame(1, $rows[1]['losses']);
        // Points-for reflects only the regular-season game, not the 60 from the playoff.
        $this->assertSame(100.0, $rows[0]['points_for']);
    }

    public function testOnlyFinalMatchupsCount(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');
        // A live win that hasn't settled must not affect standings.
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id, home_score, away_score, status)'
            . " VALUES (?,?,1,?,?,99,10,'live')"
        )->execute([$this->leagueId, $this->seasonId, $a, $b]);

        $rows = (new StandingsService($this->pdo))->compute($this->seasonId);
        $this->assertSame([], $rows);
    }
}
