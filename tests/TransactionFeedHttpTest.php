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
 * The read-only /transactions activity feed renders Roster-changing Transactions
 * in plain English.
 */
final class TransactionFeedHttpTest extends DatabaseTestCase
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
            'roster.qb' => '1', 'roster.rb' => '0', 'roster.wr' => '0', 'roster.te' => '0',
            'roster.flex' => '0', 'roster.k' => '0', 'roster.def' => '0', 'roster.bench' => '0',
        ]);
    }

    public function testFeedShowsAnAddDropInPlainEnglish(): void
    {
        $teamId = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, 'Sharks');
        $userId = (new UserRepository($this->pdo))->create($this->leagueId, 'mgr', 'password1', 'manager', 'Boss');
        (new TeamRepository($this->pdo))->assignManager($teamId, $userId);

        $players = new PlayerRepository($this->pdo);
        $players->upsert('OLD', null, 'Old Guy', 'QB', 'KC', 'Active', 1);
        $players->upsert('NEW', null, 'New Guy', 'QB', 'KC', 'Active', 2);
        $this->pdo->prepare(
            "INSERT INTO rosters (league_id, season_id, team_id, player_id, acquired) VALUES (?,?,?,?,'draft')"
        )->execute([$this->leagueId, $this->seasonId, $teamId, 'OLD']);

        // Make the add/drop through the real endpoint.
        $this->dispatch($userId, 'POST', '/players/add', ['add_player_id' => 'NEW', 'drop_player_id' => 'OLD']);

        $response = $this->dispatch($userId, 'GET', '/transactions');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Sharks', $response->body);
        $this->assertStringContainsString('added New Guy', $response->body);
        $this->assertStringContainsString('dropped Old Guy', $response->body);
    }

    public function testEmptyFeedRendersAFriendlyMessage(): void
    {
        $userId = (new UserRepository($this->pdo))->create($this->leagueId, 'solo', 'password1', 'manager', 'Solo');

        $response = $this->dispatch($userId, 'GET', '/transactions');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('No transactions yet', $response->body);
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
