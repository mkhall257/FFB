<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Scoring\MatchupScoringService;
use FFB\Scoring\ScoringEngine;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class MatchupScoringTest extends DatabaseTestCase
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

    private function service(): MatchupScoringService
    {
        return new MatchupScoringService(
            new MatchupRepository($this->pdo),
            new LineupRepository($this->pdo),
            new PlayerWeekStatsRepository($this->pdo),
            new ScoringEngine(),
            new LeagueSettingsRepository($this->pdo),
        );
    }

    private function team(string $name): int
    {
        return (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, $name);
    }

    private function startQb(int $team, string $playerId): void
    {
        (new PlayerRepository($this->pdo))->upsert($playerId, null, $playerId, 'QB', 'KC', 'Active', 1);
        (new LineupRepository($this->pdo))->replaceForTeamWeek($this->leagueId, $this->seasonId, 1, $team, [
            ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => $playerId],
        ]);
    }

    private function matchup(int $home, int $away): void
    {
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id) VALUES (?,?,1,?,?)'
        )->execute([$this->leagueId, $this->seasonId, $home, $away]);
    }

    public function testScoresAMatchupFromStartersAndStats(): void
    {
        $home = $this->team('Home');
        $away = $this->team('Away');
        $this->startQb($home, 'HQB');
        $this->startQb($away, 'AQB');
        $this->matchup($home, $away);

        $stats = new PlayerWeekStatsRepository($this->pdo);
        $stats->upsert($this->seasonId, 1, 'HQB', 'sleeper', ['pass_yard' => 300, 'pass_td' => 2]); // 12 + 8 = 20.0
        $stats->upsert($this->seasonId, 1, 'AQB', 'sleeper', ['pass_yard' => 100]);                  // 4.0

        $this->service()->scoreWeek($this->leagueId, $this->seasonId, 1, 'live');

        $row = $this->pdo->query('SELECT home_score, away_score, status FROM matchups')->fetch();
        $this->assertSame('20.00', $row['home_score']);
        $this->assertSame('4.00', $row['away_score']);
        $this->assertSame('live', $row['status']);
    }

    public function testOfficialStatsSupersedeLiveWhenPresent(): void
    {
        $home = $this->team('Home');
        $away = $this->team('Away');
        $this->startQb($home, 'HQB');
        $this->startQb($away, 'AQB');
        $this->matchup($home, $away);

        $stats = new PlayerWeekStatsRepository($this->pdo);
        // Live says 20.0, official corrects HQB down to 200 yds (8.0).
        $stats->upsert($this->seasonId, 1, 'HQB', 'sleeper', ['pass_yard' => 300, 'pass_td' => 2]);
        $stats->upsert($this->seasonId, 1, 'HQB', 'nflverse', ['pass_yard' => 200]); // 8.0
        $stats->upsert($this->seasonId, 1, 'AQB', 'sleeper', ['pass_yard' => 100]);   // 4.0

        $this->service()->scoreWeek($this->leagueId, $this->seasonId, 1, 'final');

        $row = $this->pdo->query('SELECT home_score, status FROM matchups')->fetch();
        $this->assertSame('8.00', $row['home_score']);
        $this->assertSame('final', $row['status']);
    }
}
