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
 * Commissioner reversal (ADR-0010): conflict-checked and non-cascading. A clean
 * reversal restores the exact prior state; a reversal blocked by later activity
 * is refused with nothing changed.
 */
final class TransactionReversalHttpTest extends DatabaseTestCase
{
    private int $leagueId;
    private int $seasonId;
    private int $commishId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
        $this->commishId = (new UserRepository($this->pdo))
            ->create($this->leagueId, 'boss', 'password1', 'commissioner', 'Commish');
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'roster.qb' => '2', 'roster.rb' => '2', 'roster.wr' => '0', 'roster.te' => '0',
            'roster.flex' => '0', 'roster.k' => '0', 'roster.def' => '0', 'roster.bench' => '5',
        ]);
    }

    public function testCommissionerReversesAnAddDropRestoringPriorState(): void
    {
        [$team, $mgr] = $this->team('Sharks', 'sharks');
        $this->roster($team, 'OLD', 'QB');
        (new PlayerRepository($this->pdo))->upsert('NEW', null, 'NEW', 'QB', 'KC', 'Active', 1);
        $this->asManager($mgr, 'POST', '/players/add', ['add_player_id' => 'NEW', 'drop_player_id' => 'OLD']);
        $txnId = $this->latest();

        $response = $this->asCommish('POST', '/admin/transactions/reverse', ['transaction_id' => (string) $txnId]);

        $this->assertSame(302, $response->status);
        $this->assertSame($team, $this->rosterTeamOf('OLD')); // restored
        $this->assertSame('draft', $this->acquiredOf('OLD')); // exact prior acquired
        $this->assertNull($this->rosterTeamOf('NEW')); // add undone
        $this->assertSame('reversed', $this->statusOf($txnId));
    }

    public function testReverseRefusedWhenDroppedPlayerWasPickedUpBySomeoneElse(): void
    {
        [$team, $mgr] = $this->team('Sharks', 'sharks');
        [$other, $mgr2] = $this->team('Bears', 'bears');
        $this->roster($team, 'OLD', 'QB');
        (new PlayerRepository($this->pdo))->upsert('NEW', null, 'NEW', 'QB', 'KC', 'Active', 1);
        $this->asManager($mgr, 'POST', '/players/add', ['add_player_id' => 'NEW', 'drop_player_id' => 'OLD']);
        $txnId = $this->latest();
        // Bears grabs the just-dropped OLD.
        $this->asManager($mgr2, 'POST', '/players/add', ['add_player_id' => 'OLD']);

        $response = $this->asCommish('POST', '/admin/transactions/reverse', ['transaction_id' => (string) $txnId]);

        $this->assertSame(409, $response->status);
        $this->assertSame($other, $this->rosterTeamOf('OLD')); // still on Bears — nothing yanked
        $this->assertSame($team, $this->rosterTeamOf('NEW')); // unchanged
        $this->assertSame('applied', $this->statusOf($txnId)); // not reversed
    }

    public function testCommissionerReversesATrade(): void
    {
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b, $ub] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');
        $this->asManager($ua, 'POST', '/trades/propose', [
            'target_team_id' => (string) $b, 'offered' => ['AQB'], 'requested' => ['BQB'],
        ]);
        $txnId = $this->latest();
        $this->asManager($ub, 'POST', '/trades/accept', ['transaction_id' => (string) $txnId]);
        $this->assertSame($b, $this->rosterTeamOf('AQB')); // sanity: trade applied

        $this->asCommish('POST', '/admin/transactions/reverse', ['transaction_id' => (string) $txnId]);

        $this->assertSame($a, $this->rosterTeamOf('AQB')); // back to Sharks
        $this->assertSame($b, $this->rosterTeamOf('BQB')); // back to Bears
        $this->assertSame('draft', $this->acquiredOf('AQB')); // prior acquired restored
        $this->assertSame('reversed', $this->statusOf($txnId));
    }

    public function testManagerCannotReverse(): void
    {
        [$team, $mgr] = $this->team('Sharks', 'sharks');
        $this->roster($team, 'OLD', 'QB');
        (new PlayerRepository($this->pdo))->upsert('NEW', null, 'NEW', 'QB', 'KC', 'Active', 1);
        $this->asManager($mgr, 'POST', '/players/add', ['add_player_id' => 'NEW', 'drop_player_id' => 'OLD']);
        $txnId = $this->latest();

        $response = $this->asManager($mgr, 'POST', '/admin/transactions/reverse', ['transaction_id' => (string) $txnId]);

        $this->assertSame(403, $response->status);
        $this->assertSame('applied', $this->statusOf($txnId));
    }

    // --- helpers ---

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

    private function latest(): int
    {
        return (int) $this->pdo->query('SELECT MAX(id) FROM transactions')->fetchColumn();
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

    private function statusOf(int $txnId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM transactions WHERE id = ?');
        $stmt->execute([$txnId]);

        return (string) $stmt->fetchColumn();
    }

    private function asManager(int $userId, string $method, string $path, array $post = []): Response
    {
        $session = new ArraySession([
            'user_id' => $userId, 'role' => 'manager',
            'league_id' => $this->leagueId, 'display_name' => 'Manager',
        ]);

        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $session);
    }

    private function asCommish(string $method, string $path, array $post = []): Response
    {
        $session = new ArraySession([
            'user_id' => $this->commishId, 'role' => 'commissioner',
            'league_id' => $this->leagueId, 'display_name' => 'Commish',
        ]);

        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $session);
    }
}
