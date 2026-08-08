<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\PlayoffRepository;
use FFB\StandingsService;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Slice 2 — creating the bracket freezes the current Standings order into the
 * top-n seeds, gated on the regular season being settled and the field size
 * being sane.
 */
final class PlayoffCreateHttpTest extends DatabaseTestCase
{
    private const REGULAR_WEEKS = 14;

    private int $leagueId;
    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
    }

    private function commissioner(): ArraySession
    {
        return new ArraySession([
            'user_id' => 9999, 'role' => 'commissioner',
            'league_id' => $this->leagueId, 'display_name' => 'Boss',
        ]);
    }

    private function manager(): ArraySession
    {
        return new ArraySession([
            'user_id' => 1, 'role' => 'manager',
            'league_id' => $this->leagueId, 'display_name' => 'Kid',
        ]);
    }

    private function dispatch(ArraySession $session, string $method, string $path, array $post = []): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $session);
    }

    /** @return list<int> the created team ids, in creation order */
    private function makeTeams(int $count): array
    {
        $teams = new TeamRepository($this->pdo);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $teams->create($this->leagueId, $this->seasonId, 'Team ' . chr(65 + $i));
        }

        return $ids;
    }

    private function matchup(int $week, ?int $round, int $home, int $away, float $hs, float $as, string $status): void
    {
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, round, home_team_id, away_team_id, home_score, away_score, status)'
            . ' VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([$this->leagueId, $this->seasonId, $week, $round, $home, $away, $hs, $as, $status]);
    }

    private function setTeamCount(int $n): void
    {
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'playoffs.team_count' => (string) $n,
        ]);
    }

    /** Settle the final regular week with a deterministic result order. */
    private function settleFinalWeek(int $a, int $b, int $c, int $d): void
    {
        // A crushes B; C edges D. Final week is round-robin (round IS NULL).
        $this->matchup(self::REGULAR_WEEKS, null, $a, $b, 150, 50, 'final');
        $this->matchup(self::REGULAR_WEEKS, null, $c, $d, 100, 90, 'final');
    }

    private function seeds(): array
    {
        return (new PlayoffRepository($this->pdo))->seeds($this->seasonId);
    }

    public function testCreateFreezesTopSeedsInStandingsOrder(): void
    {
        [$a, $b, $c, $d] = $this->makeTeams(4);
        $this->settleFinalWeek($a, $b, $c, $d);
        $this->setTeamCount(4);

        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/create');
        $this->assertSame(302, $response->status);

        // Seeds must match the Standings order exactly.
        $expected = [];
        $seed = 1;
        foreach ((new StandingsService($this->pdo))->compute($this->seasonId) as $row) {
            $expected[$seed++] = (int) $row['team_id'];
        }
        $this->assertSame($expected, $this->seeds());
        $this->assertSame($a, $this->seeds()[1], 'A won biggest, must be the #1 seed');
    }

    public function testCreateTakesOnlyTheTopNSeeds(): void
    {
        [$a, $b, $c, $d] = $this->makeTeams(4);
        $this->settleFinalWeek($a, $b, $c, $d);
        $this->setTeamCount(2);

        $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/create');

        $this->assertCount(2, $this->seeds());
        $this->assertSame($a, $this->seeds()[1]);
    }

    public function testCreateRefusedBeforeRegularSeasonSettled(): void
    {
        [$a, $b, $c, $d] = $this->makeTeams(4);
        // Final week is still live, not final.
        $this->matchup(self::REGULAR_WEEKS, null, $a, $b, 99, 10, 'live');
        $this->setTeamCount(4);

        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/create');

        $this->assertSame(409, $response->status);
        $this->assertSame([], $this->seeds());
    }

    public function testCreateRefusedWhenFieldBiggerThanLeague(): void
    {
        [$a, $b, $c, $d] = $this->makeTeams(4);
        $this->settleFinalWeek($a, $b, $c, $d);
        $this->setTeamCount(9);

        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/create');

        $this->assertSame(422, $response->status);
        $this->assertSame([], $this->seeds());
    }

    public function testCreateRefusedTwice(): void
    {
        [$a, $b, $c, $d] = $this->makeTeams(4);
        $this->settleFinalWeek($a, $b, $c, $d);
        $this->setTeamCount(4);

        $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/create');
        $again = $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/create');

        $this->assertSame(409, $again->status);
        $this->assertCount(4, $this->seeds());
    }

    public function testManagerCannotCreate(): void
    {
        $response = $this->dispatch($this->manager(), 'POST', '/admin/playoffs/create');
        $this->assertSame(403, $response->status);
    }

    public function testCommissionerCanSaveTeamCount(): void
    {
        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/season/playoffs', [
            'team_count' => '6',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('6', (new LeagueSettingsRepository($this->pdo))
            ->all($this->leagueId, $this->seasonId)['playoffs.team_count']);
    }
}
