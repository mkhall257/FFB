<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;
use PDOException;

final class PlayoffSchemaTest extends DatabaseTestCase
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

    public function testMatchupsRoundDefaultsToNullForRegularSeason(): void
    {
        $home = $this->team('Home');
        $away = $this->team('Away');
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id)'
            . ' VALUES (?, ?, 1, ?, ?)'
        )->execute([$this->leagueId, $this->seasonId, $home, $away]);

        $round = $this->pdo->query('SELECT round FROM matchups')->fetchColumn();
        $this->assertNull($round);
    }

    public function testMatchupsAcceptsAPlayoffRound(): void
    {
        $home = $this->team('Home');
        $away = $this->team('Away');
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, round, home_team_id, away_team_id)'
            . ' VALUES (?, ?, 15, 1, ?, ?)'
        )->execute([$this->leagueId, $this->seasonId, $home, $away]);

        $round = $this->pdo->query('SELECT round FROM matchups')->fetchColumn();
        $this->assertSame(1, (int) $round);
    }

    public function testPlayoffSeedsStoresAFrozenSeed(): void
    {
        $team = $this->team('Top');
        $this->pdo->prepare(
            'INSERT INTO playoff_seeds (league_id, season_id, seed, team_id) VALUES (?, ?, 1, ?)'
        )->execute([$this->leagueId, $this->seasonId, $team]);

        $row = $this->pdo->query('SELECT seed, team_id FROM playoff_seeds')->fetch();
        $this->assertSame(1, (int) $row['seed']);
        $this->assertSame($team, (int) $row['team_id']);
    }

    public function testASeasonCannotHaveTwoTeamsAtTheSameSeed(): void
    {
        $a = $this->team('A');
        $b = $this->team('B');
        $insert = $this->pdo->prepare(
            'INSERT INTO playoff_seeds (league_id, season_id, seed, team_id) VALUES (?, ?, 1, ?)'
        );
        $insert->execute([$this->leagueId, $this->seasonId, $a]);

        $this->expectException(PDOException::class);
        $insert->execute([$this->leagueId, $this->seasonId, $b]);
    }

    public function testASeasonCannotSeedTheSameTeamTwice(): void
    {
        $a = $this->team('A');
        $this->pdo->prepare(
            'INSERT INTO playoff_seeds (league_id, season_id, seed, team_id) VALUES (?, ?, 1, ?)'
        )->execute([$this->leagueId, $this->seasonId, $a]);

        $this->expectException(PDOException::class);
        $this->pdo->prepare(
            'INSERT INTO playoff_seeds (league_id, season_id, seed, team_id) VALUES (?, ?, 2, ?)'
        )->execute([$this->leagueId, $this->seasonId, $a]);
    }

    public function testPlayoffTeamCountDefaultsToFour(): void
    {
        $settings = (new LeagueSettingsRepository($this->pdo))->all($this->leagueId, $this->seasonId);
        $this->assertSame('4', $settings['playoffs.team_count'] ?? null);
    }
}
