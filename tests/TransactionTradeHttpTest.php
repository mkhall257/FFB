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
 * The Trade lifecycle: propose -> accept / reject / cancel. A proposal moves
 * nothing until the target accepts, when both Rosters swap atomically.
 */
final class TransactionTradeHttpTest extends DatabaseTestCase
{
    private int $leagueId;
    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
        // Generous cap so trades don't trip the roster limit here.
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'roster.qb' => '2', 'roster.rb' => '2', 'roster.wr' => '2', 'roster.te' => '0',
            'roster.flex' => '0', 'roster.k' => '0', 'roster.def' => '0', 'roster.bench' => '5',
        ]);
        $this->pdo->prepare("INSERT INTO drafts (league_id, season_id, state) VALUES (?,?,'complete')")
            ->execute([$this->leagueId, $this->seasonId]);
    }

    public function testProposeCreatesAPendingTradeVisibleToTheTarget(): void
    {
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b, $ub] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');

        $response = $this->propose($ua, $b, ['AQB'], ['BQB']);
        $this->assertSame(302, $response->status);

        // Target sees it as incoming; nothing has moved yet.
        $this->assertSame($a, $this->rosterTeamOf('AQB'));
        $this->assertSame($b, $this->rosterTeamOf('BQB'));
        $target = $this->dispatch($ub, 'GET', '/trades');
        $this->assertStringContainsString('AQB', $target->body);
        $this->assertStringContainsString('BQB', $target->body);
    }

    public function testTargetAcceptsAndBothRostersSwap(): void
    {
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b, $ub] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');

        $this->propose($ua, $b, ['AQB'], ['BQB']);
        $txnId = $this->latestTradeId();

        $response = $this->dispatch($ub, 'POST', '/trades/accept', ['transaction_id' => (string) $txnId]);
        $this->assertSame(302, $response->status);

        $this->assertSame($b, $this->rosterTeamOf('AQB')); // AQB now on Bears
        $this->assertSame($a, $this->rosterTeamOf('BQB')); // BQB now on Sharks
        $this->assertSame('trade', $this->acquiredOf('AQB'));
        $this->assertSame('applied', $this->statusOf($txnId));
        $this->assertSame('accepted', $this->outcomeOf($txnId));
    }

    public function testTargetRejectsAndNothingMoves(): void
    {
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b, $ub] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');
        $this->propose($ua, $b, ['AQB'], ['BQB']);
        $txnId = $this->latestTradeId();

        $this->dispatch($ub, 'POST', '/trades/reject', ['transaction_id' => (string) $txnId]);

        $this->assertSame($a, $this->rosterTeamOf('AQB'));
        $this->assertSame($b, $this->rosterTeamOf('BQB'));
        $this->assertSame('rejected', $this->outcomeOf($txnId));
    }

    public function testProposerCancelsAndNothingMoves(): void
    {
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');
        $this->propose($ua, $b, ['AQB'], ['BQB']);
        $txnId = $this->latestTradeId();

        $this->dispatch($ua, 'POST', '/trades/cancel', ['transaction_id' => (string) $txnId]);

        $this->assertSame($a, $this->rosterTeamOf('AQB'));
        $this->assertSame('cancelled', $this->outcomeOf($txnId));
    }

    public function testProposerCannotAcceptTheirOwnTrade(): void
    {
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');
        $this->propose($ua, $b, ['AQB'], ['BQB']);
        $txnId = $this->latestTradeId();

        $response = $this->dispatch($ua, 'POST', '/trades/accept', ['transaction_id' => (string) $txnId]);

        $this->assertGreaterThanOrEqual(400, $response->status);
        $this->assertSame($a, $this->rosterTeamOf('AQB')); // unmoved
    }

    public function testProposeRejectsPlayersTheProposerDoesNotOwn(): void
    {
        [$a, $ua] = $this->team('Sharks', 'sharks');
        [$b] = $this->team('Bears', 'bears');
        $this->roster($a, 'AQB', 'QB');
        $this->roster($b, 'BQB', 'QB');

        // Sharks offers BQB (which belongs to Bears) — invalid.
        $response = $this->propose($ua, $b, ['BQB'], []);

        $this->assertGreaterThanOrEqual(400, $response->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM transactions WHERE type='trade'")->fetchColumn());
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
