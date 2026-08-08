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
use FFB\Scoring\SettlementService;
use FFB\Scoring\StatsImporter;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class SettlementServiceTest extends DatabaseTestCase
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

    private function settlement(): SettlementService
    {
        $stats = new PlayerWeekStatsRepository($this->pdo);
        $importer = new StatsImporter($stats, new PlayerRepository($this->pdo));
        $scoring = new MatchupScoringService(
            new MatchupRepository($this->pdo),
            new LineupRepository($this->pdo),
            $stats,
            new ScoringEngine(),
            new LeagueSettingsRepository($this->pdo),
        );

        return new SettlementService($importer, $scoring, new MatchupRepository($this->pdo));
    }

    private function startQb(int $team, string $sleeperId, string $gsisId): void
    {
        (new PlayerRepository($this->pdo))->upsert($sleeperId, $gsisId, $sleeperId, 'QB', 'KC', 'Active', 1);
        (new LineupRepository($this->pdo))->replaceForTeamWeek($this->leagueId, $this->seasonId, 1, $team, [
            ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => $sleeperId],
        ]);
    }

    public function testOfficialStatsSettleAndSetTheResult(): void
    {
        $home = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, 'Home');
        $away = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, 'Away');
        $this->startQb($home, 'HQB', 'GSIS_H');
        $this->startQb($away, 'AQB', 'GSIS_A');
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id) VALUES (?,?,1,?,?)'
        )->execute([$this->leagueId, $this->seasonId, $home, $away]);

        // Official: away outscores home.
        $this->settlement()->settleWeek($this->leagueId, $this->seasonId, 1, [
            'GSIS_H' => ['pass_yard' => 100],                   // 4.0
            'GSIS_A' => ['pass_yard' => 300, 'pass_td' => 2],   // 20.0
        ]);

        $row = $this->pdo->query('SELECT home_score, away_score, status FROM matchups')->fetch();
        $this->assertSame('final', $row['status']);
        $this->assertSame('4.00', $row['home_score']);
        $this->assertSame('20.00', $row['away_score']);
    }

    public function testSettlementCanFlipALiveResult(): void
    {
        $home = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, 'Home');
        $away = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, 'Away');
        $this->startQb($home, 'HQB', 'GSIS_H');
        $this->startQb($away, 'AQB', 'GSIS_A');
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id) VALUES (?,?,1,?,?)'
        )->execute([$this->leagueId, $this->seasonId, $home, $away]);

        // Live had home winning big.
        $stats = new PlayerWeekStatsRepository($this->pdo);
        $stats->upsert($this->seasonId, 1, 'HQB', 'sleeper', ['pass_yard' => 400, 'pass_td' => 4]);
        $stats->upsert($this->seasonId, 1, 'AQB', 'sleeper', ['pass_yard' => 50]);
        (new MatchupScoringService(
            new MatchupRepository($this->pdo),
            new LineupRepository($this->pdo),
            $stats,
            new ScoringEngine(),
            new LeagueSettingsRepository($this->pdo),
        ))->scoreWeek($this->leagueId, $this->seasonId, 1, 'live');

        // Official correction: home QB downgraded, away wins.
        $this->settlement()->settleWeek($this->leagueId, $this->seasonId, 1, [
            'GSIS_H' => ['pass_yard' => 100],                   // 4.0
            'GSIS_A' => ['pass_yard' => 300, 'pass_td' => 2],   // 20.0
        ]);

        $row = $this->pdo->query('SELECT home_score, away_score, status FROM matchups')->fetch();
        $this->assertSame('final', $row['status']);
        $this->assertTrue((float) $row['away_score'] > (float) $row['home_score'], 'settlement flipped the winner');
    }
}
