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

final class TransactionAddDropHttpTest extends DatabaseTestCase
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

    public function testManagerAddsFreeAgentToAnOpenBenchSpot(): void
    {
        $this->setRosterShape(['qb' => 1, 'bench' => 1]); // cap = 2
        [$teamId, $userId] = $this->managedTeam('Sharks');
        $this->roster($teamId, 'QB1', 'QB'); // one rostered, cap 2 -> room for one more
        $this->player('FA1', 'RB');

        $response = $this->dispatch($userId, 'POST', '/players/add', ['add_player_id' => 'FA1']);

        $this->assertSame(302, $response->status);
        $this->assertSame('/players', $response->headers['Location']);
        $this->assertSame($teamId, $this->rosterTeamOf('FA1'));
        $this->assertSame('add', $this->acquiredOf('FA1'));
        $this->assertSame(1, $this->countTransactions('add_drop'));
        // one item: the add (from pool -> team), no drop
        $this->assertSame(1, $this->countItems());
    }

    public function testAtCapAddRequiresADropAndAppliesBoth(): void
    {
        $this->setRosterShape(['qb' => 1, 'bench' => 0]); // cap = 1
        [$teamId, $userId] = $this->managedTeam('Bears');
        $this->roster($teamId, 'OLD', 'QB'); // full
        $this->player('NEW', 'QB');

        $response = $this->dispatch($userId, 'POST', '/players/add', [
            'add_player_id' => 'NEW',
            'drop_player_id' => 'OLD',
        ]);

        $this->assertSame(302, $response->status);
        $this->assertSame($teamId, $this->rosterTeamOf('NEW'));
        $this->assertNull($this->rosterTeamOf('OLD')); // dropped to pool
        $this->assertSame(2, $this->countItems()); // add + drop
    }

    public function testAtCapAddWithoutADropIsRefused(): void
    {
        $this->setRosterShape(['qb' => 1, 'bench' => 0]); // cap = 1
        [$teamId, $userId] = $this->managedTeam('Colts');
        $this->roster($teamId, 'OLD', 'QB'); // full
        $this->player('NEW', 'QB');

        $response = $this->dispatch($userId, 'POST', '/players/add', ['add_player_id' => 'NEW']);

        $this->assertSame(422, $response->status);
        $this->assertNull($this->rosterTeamOf('NEW')); // nothing added
        $this->assertSame($teamId, $this->rosterTeamOf('OLD')); // nothing dropped
        $this->assertSame(0, $this->countTransactions('add_drop'));
    }

    public function testCannotAddAnAlreadyRosteredPlayer(): void
    {
        $this->setRosterShape(['qb' => 1, 'bench' => 3]);
        [, $userId] = $this->managedTeam('Jets');
        [$otherTeam] = $this->managedTeam('Rams', 'rams');
        $this->roster($otherTeam, 'TAKEN', 'QB');

        $response = $this->dispatch($userId, 'POST', '/players/add', ['add_player_id' => 'TAKEN']);

        $this->assertSame(422, $response->status);
        $this->assertSame($otherTeam, $this->rosterTeamOf('TAKEN')); // unmoved
    }

    public function testCannotDropAPlayerNotOnYourTeam(): void
    {
        $this->setRosterShape(['qb' => 1, 'bench' => 0]);
        [, $userId] = $this->managedTeam('Bills');
        [$otherTeam] = $this->managedTeam('Dolphins', 'fins');
        $this->roster($otherTeam, 'THEIRS', 'QB');
        $this->player('WANT', 'QB');

        $response = $this->dispatch($userId, 'POST', '/players/add', [
            'add_player_id' => 'WANT',
            'drop_player_id' => 'THEIRS',
        ]);

        $this->assertSame(422, $response->status);
        $this->assertNull($this->rosterTeamOf('WANT'));
        $this->assertSame($otherTeam, $this->rosterTeamOf('THEIRS'));
    }

    public function testFreeAgentListExcludesRosteredPlayers(): void
    {
        $this->setRosterShape(['qb' => 1, 'bench' => 3]);
        [$teamId, $userId] = $this->managedTeam('Chiefs');
        $this->roster($teamId, 'MINE', 'QB');
        $this->player('AVAIL', 'RB');

        $response = $this->dispatch($userId, 'GET', '/players');

        $this->assertSame(200, $response->status);
        // The available-players list marks each row with data-player.
        $this->assertStringContainsString('data-player="AVAIL"', $response->body);
        $this->assertStringNotContainsString('data-player="MINE"', $response->body);
    }

    // --- helpers ---

    private function setRosterShape(array $shape): void
    {
        $settings = [];
        foreach (['qb', 'rb', 'wr', 'te', 'flex', 'k', 'def', 'bench'] as $slot) {
            $settings['roster.' . $slot] = (string) ($shape[$slot] ?? 0);
        }
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, $settings);
    }

    /** @return array{0:int,1:int} [teamId, userId] */
    private function managedTeam(string $name, string $username = 'mgr'): array
    {
        $teamId = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, $name);
        $userId = (new UserRepository($this->pdo))->create($this->leagueId, $username, 'password1', 'manager', $name);
        (new TeamRepository($this->pdo))->assignManager($teamId, $userId);

        return [$teamId, $userId];
    }

    private function player(string $id, string $pos): void
    {
        (new PlayerRepository($this->pdo))->upsert($id, null, $id, $pos, 'KC', 'Active', 1);
    }

    private function roster(int $teamId, string $playerId, string $pos): void
    {
        $this->player($playerId, $pos);
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

    private function acquiredOf(string $playerId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT acquired FROM rosters WHERE season_id = ? AND player_id = ?');
        $stmt->execute([$this->seasonId, $playerId]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    private function countTransactions(string $type): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM transactions WHERE type = ?');
        $stmt->execute([$type]);

        return (int) $stmt->fetchColumn();
    }

    private function countItems(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM transaction_items')->fetchColumn();
    }

    private function session(int $userId): ArraySession
    {
        return new ArraySession([
            'user_id' => $userId, 'role' => 'manager',
            'league_id' => $this->leagueId, 'display_name' => 'Manager',
        ]);
    }

    private function dispatch(int $userId, string $method, string $path, array $post = []): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $this->session($userId));
    }
}
