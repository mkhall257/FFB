<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Scoring\MatchupDetailService;
use FFB\Scoring\ScoringEngine;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class MatchupDetailTest extends DatabaseTestCase
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

    private function service(): MatchupDetailService
    {
        return new MatchupDetailService(
            new MatchupRepository($this->pdo),
            new LineupRepository($this->pdo),
            new PlayerWeekStatsRepository($this->pdo),
            new PlayerRepository($this->pdo),
            new ScoringEngine(),
            new LeagueSettingsRepository($this->pdo),
        );
    }

    private function team(string $name): int
    {
        return (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, $name);
    }

    private function matchup(int $home, int $away): void
    {
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id) VALUES (?,?,1,?,?)'
        )->execute([$this->leagueId, $this->seasonId, $home, $away]);
    }

    public function testExpandsMatchupToPerStarterRowsWithIdentityAndTotals(): void
    {
        $home = $this->team('Home');
        $away = $this->team('Away');
        $this->matchup($home, $away);

        $players = new PlayerRepository($this->pdo);
        $players->upsert('HQB', null, 'Josh Home', 'QB', 'BUF', 'Active', 1);
        $players->upsert('HWR', null, 'Wide Home', 'WR', 'MIA', 'Questionable', 2);
        $players->upsert('AQB', null, 'Pat Away', 'QB', 'KC', 'Out', 3);

        $lineups = new LineupRepository($this->pdo);
        // Insert WR before QB to prove the service sorts into canonical slot order.
        $lineups->replaceForTeamWeek($this->leagueId, $this->seasonId, 1, $home, [
            ['roster_slot' => 'WR', 'slot_index' => 0, 'player_id' => 'HWR'],
            ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => 'HQB'],
        ]);
        $lineups->replaceForTeamWeek($this->leagueId, $this->seasonId, 1, $away, [
            ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => 'AQB'],
        ]);

        $stats = new PlayerWeekStatsRepository($this->pdo);
        $stats->upsert($this->seasonId, 1, 'HQB', 'sleeper', ['pass_yard' => 300, 'pass_td' => 2]); // 20.0
        $stats->upsert($this->seasonId, 1, 'HWR', 'sleeper', ['rec_yard' => 50]);                    // 5.0
        $stats->upsert($this->seasonId, 1, 'AQB', 'sleeper', ['pass_yard' => 100]);                  // 4.0

        $detail = $this->service()->forWeek($this->leagueId, $this->seasonId, 1);

        $this->assertCount(1, $detail);
        $m = $detail[0];
        $this->assertSame('scheduled', $m['status']);

        // Home total = 25.0, and starters sorted QB then WR (not insertion order).
        $this->assertSame(25.0, $m['home']['total']);
        $this->assertSame(['QB', 'WR'], array_column($m['home']['starters'], 'slot'));

        $qb = $m['home']['starters'][0];
        $this->assertSame('Josh Home', $qb['name']);
        $this->assertSame('BUF', $qb['nfl_team']);
        $this->assertSame('Active', $qb['status']);
        $this->assertSame(20.0, $qb['points']);

        $this->assertSame('Questionable', $m['home']['starters'][1]['status']);

        // Away carries identity + status through too.
        $this->assertSame(4.0, $m['away']['total']);
        $this->assertSame('Out', $m['away']['starters'][0]['status']);
    }
}
