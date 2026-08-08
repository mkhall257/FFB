<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\PlayerRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;
use FFB\UserRepository;

/**
 * Timing gates (ADR-0010): transactions open only once the Draft is complete;
 * a Commissioner-set trade deadline closes trading (Add/Drop stays open).
 */
final class TransactionTimingHttpTest extends DatabaseTestCase
{
    private int $leagueId;
    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'roster.qb' => '2', 'roster.rb' => '2', 'roster.wr' => '0', 'roster.te' => '0',
            'roster.flex' => '0', 'roster.k' => '0', 'roster.def' => '0', 'roster.bench' => '5',
            'schedule.current_week' => '6',
        ]);
    }

    public function testAddDropRefusedBeforeDraftIsComplete(): void
    {
        $this->draft('setup');
        [, $mgr] = $this->team('Sharks', 'sharks');
        (new PlayerRepository($this->pdo))->upsert('FA', null, 'FA', 'QB', 'KC', 'Active', 1);

        $response = $this->dispatch($mgr, 'POST', '/players/add', ['add_player_id' => 'FA']);

        $this->assertSame(403, $response->status);
        $this->assertNull($this->rosterTeamOf('FA'));
    }

    public function testAddDropAllowedAfterDraftIsComplete(): void
    {
        $this->draft('complete');
        [, $mgr] = $this->team('Sharks', 'sharks');
        (new PlayerRepository($this->pdo))->upsert('FA', null, 'FA', 'QB', 'KC', 'Active', 1);

        $response = $this->dispatch($mgr, 'POST', '/players/add', ['add_player_id' => 'FA']);

        $this->assertSame(302, $response->status);
        $this->assertNotNull($this->rosterTeamOf('FA'));
    }

    public function testTradeRefusedPastTheDeadlineButAddDropStaysOpen(): void
    {
        $this->draft('complete');
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'schedule.trade_deadline_week' => '5', // current week is 6 -> closed
        ]);
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');
        (new PlayerRepository($this->pdo))->upsert('FA', null, 'FA', 'QB', 'KC', 'Active', 1);

        $trade = $this->dispatch($ua, 'POST', '/trades/propose', [
            'target_team_id' => (string) $b, 'offered' => ['AQB'], 'requested' => ['BQB'],
        ]);
        $this->assertSame(403, $trade->status);

        // Add/Drop is unaffected by the trade deadline.
        $add = $this->dispatch($ua, 'POST', '/players/add', ['add_player_id' => 'FA']);
        $this->assertSame(302, $add->status);
    }

    public function testTradeAllowedOnTheDeadlineWeek(): void
    {
        $this->draft('complete');
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'schedule.current_week' => '5', 'schedule.trade_deadline_week' => '5',
        ]);
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');

        $response = $this->dispatch($ua, 'POST', '/trades/propose', [
            'target_team_id' => (string) $b, 'offered' => ['AQB'], 'requested' => ['BQB'],
        ]);

        $this->assertSame(302, $response->status);
    }

    // --- helpers ---

    private function draft(string $state): void
    {
        $this->pdo->prepare('INSERT INTO drafts (league_id, season_id, state) VALUES (?,?,?)')
            ->execute([$this->leagueId, $this->seasonId, $state]);
    }

    /** @return array{0:int,1:int} */
    private function team(string $name, string $username): array
    {
        $teamId = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, $name);
        $userId = (new UserRepository($this->pdo))->create($this->leagueId, $username, 'password1', 'manager', $name);
        (new TeamRepository($this->pdo))->assignManager($teamId, $userId);

        return [$teamId, $userId];
    }

    private function roster(int $teamId, string $playerId, string $pos): void
    {
        (new PlayerRepository($this->pdo))->upsert($playerId, null, $playerId, $pos, 'KC', 'Active', 1);
        $this->pdo->prepare(
            "INSERT INTO rosters (league_id, season_id, team_id, player_id, acquired) VALUES (?,?,?,?,'draft')"
        )->execute([$this->leagueId, $this->seasonId, $teamId, $playerId]);
    }

    private function rosterTeamOf(string $playerId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT team_id FROM rosters WHERE season_id = ? AND player_id = ?');
        $stmt->execute([$this->seasonId, $playerId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function dispatch(int $userId, string $method, string $path, array $post = []): Response
    {
        $session = new ArraySession([
            'user_id' => $userId, 'role' => 'manager',
            'league_id' => $this->leagueId, 'display_name' => 'Manager',
        ]);

        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $session);
    }
}
