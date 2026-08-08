<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\Lineup\LineupService;
use FFB\PlayerRepository;
use FFB\RosterRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class LineupServiceTest extends DatabaseTestCase
{
    private function leagueId(): int
    {
        return (new LeagueRepository($this->pdo))->currentLeagueId();
    }

    private function seasonId(): int
    {
        return (new LeagueRepository($this->pdo))->currentSeasonId();
    }

    private function service(): LineupService
    {
        return new LineupService(
            new LineupRepository($this->pdo),
            new RosterRepository($this->pdo),
            new LeagueSettingsRepository($this->pdo),
        );
    }

    private function seedRosteredTeam(): int
    {
        $team = (new TeamRepository($this->pdo))->create($this->leagueId(), $this->seasonId(), 'T1');
        $players = new PlayerRepository($this->pdo);
        foreach ([['QB1', 'QB'], ['RB1', 'RB'], ['WR1', 'WR']] as [$id, $pos]) {
            $players->upsert($id, null, $id, $pos, 'KC', 'Active', 1);
            $this->pdo->prepare(
                'INSERT INTO rosters (league_id, season_id, team_id, player_id) VALUES (?,?,?,?)'
            )->execute([$this->leagueId(), $this->seasonId(), $team, $id]);
        }

        return $team;
    }

    public function testWeekOneAutoFillsRequiredSlots(): void
    {
        $team = $this->seedRosteredTeam();
        $this->service()->ensureLineup($this->leagueId(), $this->seasonId(), 1, $team);

        $rows = (new LineupRepository($this->pdo))->forTeamWeek($this->seasonId(), 1, $team);
        $filled = array_filter($rows, fn ($r) => $r['player_id'] !== null);
        $filledIds = array_column($filled, 'player_id');

        $this->assertContains('QB1', $filledIds);
        $this->assertContains('RB1', $filledIds);
        $this->assertContains('WR1', $filledIds);
    }

    public function testAutoFillNeverStartsAPlayerTwice(): void
    {
        $team = $this->seedRosteredTeam();
        $this->service()->ensureLineup($this->leagueId(), $this->seasonId(), 1, $team);

        $rows = (new LineupRepository($this->pdo))->forTeamWeek($this->seasonId(), 1, $team);
        $ids = array_filter(array_column($rows, 'player_id'));
        $this->assertSame(array_values($ids), array_values(array_unique($ids)));
    }

    public function testWeekTwoCarriesForwardWeekOne(): void
    {
        $team = $this->seedRosteredTeam();
        $this->service()->ensureLineup($this->leagueId(), $this->seasonId(), 1, $team);
        $week1 = (new LineupRepository($this->pdo))->forTeamWeek($this->seasonId(), 1, $team);

        $this->service()->ensureLineup($this->leagueId(), $this->seasonId(), 2, $team);
        $week2 = (new LineupRepository($this->pdo))->forTeamWeek($this->seasonId(), 2, $team);

        $this->assertSame(
            array_column($week1, 'player_id'),
            array_column($week2, 'player_id'),
            'week 2 lineup should carry forward week 1',
        );
    }

    public function testEnsureLineupIsIdempotent(): void
    {
        $team = $this->seedRosteredTeam();
        $svc = $this->service();
        $svc->ensureLineup($this->leagueId(), $this->seasonId(), 1, $team);
        $first = (new LineupRepository($this->pdo))->forTeamWeek($this->seasonId(), 1, $team);
        $svc->ensureLineup($this->leagueId(), $this->seasonId(), 1, $team);
        $second = (new LineupRepository($this->pdo))->forTeamWeek($this->seasonId(), 1, $team);

        $this->assertSame($first, $second);
    }
}
