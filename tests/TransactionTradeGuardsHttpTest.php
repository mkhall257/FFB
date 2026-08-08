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
 * Trade guards: lazy 48h expiry, accept-time roster-cap re-validation, and the
 * incoming-offer badge.
 */
final class TransactionTradeGuardsHttpTest extends DatabaseTestCase
{
    private int $leagueId;
    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
        $this->pdo->prepare("INSERT INTO drafts (league_id, season_id, state) VALUES (?,?,'complete')")
            ->execute([$this->leagueId, $this->seasonId]);
    }

    public function testExpiredProposalCannotBeAccepted(): void
    {
        $this->cap(['qb' => 2, 'rb' => 2, 'bench' => 5]);
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b, $ub] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');
        $this->propose($ua, $b, ['AQB'], ['BQB']);
        $txnId = $this->latestTradeId();

        // Force the offer into the past.
        $this->pdo->exec("UPDATE transactions SET expires_at = '2000-01-01 00:00:00' WHERE id = " . $txnId);

        $response = $this->dispatch($ub, 'POST', '/trades/accept', ['transaction_id' => (string) $txnId]);

        $this->assertSame(409, $response->status);
        $this->assertSame($a, $this->rosterTeamOf('AQB')); // unmoved
        $this->assertSame('expired', $this->outcomeOf($txnId));
    }

    public function testExpiredProposalDropsOffTheIncomingListOnView(): void
    {
        $this->cap(['qb' => 2, 'bench' => 5]);
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b, $ub] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');
        $this->propose($ua, $b, ['AQB'], ['BQB']);
        $txnId = $this->latestTradeId();
        $this->pdo->exec("UPDATE transactions SET expires_at = '2000-01-01 00:00:00' WHERE id = " . $txnId);

        $response = $this->dispatch($ub, 'GET', '/trades');

        $this->assertStringContainsString('No incoming offers', $response->body);
        $this->assertSame('expired', $this->outcomeOf($txnId));
    }

    public function testAcceptIsBlockedWhenItWouldOverflowTheReceiver(): void
    {
        // cap = 3 (1 QB + 1 RB + 1 WR, no bench)
        $this->cap(['qb' => 1, 'rb' => 1, 'wr' => 1, 'bench' => 0]);
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b, $ub] = $this->team('Bears', 'bears');
        // Proposer offers 2, wants 1 -> receiver (Bears) would go from 3 to 4.
        $this->roster($a, 'A1', 'QB');
        $this->roster($a, 'A2', 'RB');
        $this->roster($b, 'B1', 'QB');
        $this->roster($b, 'B2', 'RB');
        $this->roster($b, 'B3', 'WR');
        $this->propose($ua, $b, ['A1', 'A2'], ['B1']);
        $txnId = $this->latestTradeId();

        $response = $this->dispatch($ub, 'POST', '/trades/accept', ['transaction_id' => (string) $txnId]);

        $this->assertSame(409, $response->status);
        $this->assertSame($a, $this->rosterTeamOf('A1')); // nothing moved
        $this->assertSame($b, $this->rosterTeamOf('B1'));
        $this->assertSame('proposed', $this->outcomeOf($txnId)); // still open to renegotiate
    }

    public function testIncomingOfferBadgeShownOnHome(): void
    {
        $this->cap(['qb' => 2, 'bench' => 5]);
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b, $ub] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');
        $this->propose($ua, $b, ['AQB'], ['BQB']);

        $home = $this->dispatch($ub, 'GET', '/');
        $this->assertStringContainsString('Trades (1)', $home->body);

        // Proposer has no incoming offers -> no count.
        $proposerHome = $this->dispatch($ua, 'GET', '/');
        $this->assertStringNotContainsString('Trades (1)', $proposerHome->body);
    }

    // --- helpers ---

    private function cap(array $shape): void
    {
        $s = [];
        foreach (['qb', 'rb', 'wr', 'te', 'flex', 'k', 'def', 'bench'] as $slot) {
            $s['roster.' . $slot] = (string) ($shape[$slot] ?? 0);
        }
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, $s);
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

    private function propose(int $userId, int $targetTeam, array $offered, array $requested): Response
    {
        return $this->dispatch($userId, 'POST', '/trades/propose', [
            'target_team_id' => (string) $targetTeam,
            'offered' => $offered,
            'requested' => $requested,
        ]);
    }

    private function latestTradeId(): int
    {
        return (int) $this->pdo->query("SELECT MAX(id) FROM transactions WHERE type='trade'")->fetchColumn();
    }

    private function rosterTeamOf(string $playerId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT team_id FROM rosters WHERE season_id = ? AND player_id = ?');
        $stmt->execute([$this->seasonId, $playerId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function outcomeOf(int $txnId): string
    {
        $stmt = $this->pdo->prepare('SELECT proposal_outcome FROM transactions WHERE id = ?');
        $stmt->execute([$txnId]);

        return (string) $stmt->fetchColumn();
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
