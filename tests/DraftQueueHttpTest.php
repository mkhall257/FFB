<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;
use FFB\UserRepository;

/**
 * Exercises the per-Manager Draft Queue through the HTTP seam: adding,
 * removing, reordering, and privacy between Managers.
 */
final class DraftQueueHttpTest extends DatabaseTestCase
{
    private function leagueId(): int
    {
        return (new LeagueRepository($this->pdo))->currentLeagueId();
    }

    private function seasonId(): int
    {
        return (new LeagueRepository($this->pdo))->currentSeasonId();
    }

    private function commissioner(): ArraySession
    {
        // A high id that never collides with the Manager users created below
        // (whose ids start at 1), so the Commissioner genuinely manages no team.
        return new ArraySession([
            'user_id' => 9999, 'role' => 'commissioner',
            'league_id' => $this->leagueId(), 'display_name' => 'Boss',
        ]);
    }

    /**
     * @return list<array{0:int,1:int}>
     */
    private function makeManagedTeams(int $count): array
    {
        $teams = new TeamRepository($this->pdo);
        $users = new UserRepository($this->pdo);
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $teamId = $teams->create($this->leagueId(), $this->seasonId(), "Team {$i}");
            $userId = $users->create($this->leagueId(), "mgr{$i}", 'password1', 'manager', "Manager {$i}");
            $teams->assignManager($teamId, $userId);
            $out[] = [$teamId, $userId];
        }

        return $out;
    }

    private function manager(int $userId): ArraySession
    {
        return new ArraySession([
            'user_id' => $userId, 'role' => 'manager',
            'league_id' => $this->leagueId(), 'display_name' => 'Kid',
        ]);
    }

    private function seedPlayer(string $id, string $position = 'RB'): void
    {
        (new PlayerRepository($this->pdo))->upsert($id, null, "Player {$id}", $position, 'KC', 'Active', 10);
    }

    /**
     * @param array<string,mixed> $post
     */
    private function dispatch(string $method, string $path, array $post, ArraySession $session): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $session);
    }

    /**
     * @return list<string> queued player ids in rank order, for a team
     */
    private function queueOf(int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT player_id FROM draft_queue WHERE team_id = ? ORDER BY rank_position'
        );
        $stmt->execute([$teamId]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param list<array{0:int,1:int}> $teams
     */
    private function finalizeDraft(array $teams): void
    {
        $order = array_map(static fn ($t) => $t[0], $teams);
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $order], $this->commissioner());
        $this->dispatch('POST', '/admin/draft/finalize', [], $this->commissioner());
    }

    public function testManagerCanAddPlayersToTheirQueueInOrder(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->seedPlayer('P2');
        $this->finalizeDraft($teams);
        $mgr = $this->manager($teams[0][1]);

        $this->dispatch('POST', '/draft/queue/add', ['player_id' => 'P1'], $mgr);
        $response = $this->dispatch('POST', '/draft/queue/add', ['player_id' => 'P2'], $mgr);

        $this->assertSame(302, $response->status);
        $this->assertSame(['P1', 'P2'], $this->queueOf($teams[0][0]));
    }

    public function testAddingTheSamePlayerTwiceIsANoOp(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->finalizeDraft($teams);
        $mgr = $this->manager($teams[0][1]);

        $this->dispatch('POST', '/draft/queue/add', ['player_id' => 'P1'], $mgr);
        $this->dispatch('POST', '/draft/queue/add', ['player_id' => 'P1'], $mgr);

        $this->assertSame(['P1'], $this->queueOf($teams[0][0]));
    }

    public function testCannotQueueANonDraftablePlayer(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->finalizeDraft($teams);

        $response = $this->dispatch('POST', '/draft/queue/add', ['player_id' => 'NOPE'], $this->manager($teams[0][1]));

        $this->assertSame(400, $response->status);
        $this->assertSame([], $this->queueOf($teams[0][0]));
    }

    public function testManagerCanRemoveFromQueueAndRanksCompact(): void
    {
        $teams = $this->makeManagedTeams(4);
        foreach (['P1', 'P2', 'P3'] as $p) {
            $this->seedPlayer($p);
        }
        $this->finalizeDraft($teams);
        $mgr = $this->manager($teams[0][1]);
        foreach (['P1', 'P2', 'P3'] as $p) {
            $this->dispatch('POST', '/draft/queue/add', ['player_id' => $p], $mgr);
        }

        $this->dispatch('POST', '/draft/queue/remove', ['player_id' => 'P2'], $mgr);

        $this->assertSame(['P1', 'P3'], $this->queueOf($teams[0][0]));
    }

    public function testManagerCanReorderQueue(): void
    {
        $teams = $this->makeManagedTeams(4);
        foreach (['P1', 'P2', 'P3'] as $p) {
            $this->seedPlayer($p);
        }
        $this->finalizeDraft($teams);
        $mgr = $this->manager($teams[0][1]);
        foreach (['P1', 'P2', 'P3'] as $p) {
            $this->dispatch('POST', '/draft/queue/add', ['player_id' => $p], $mgr);
        }

        $this->dispatch('POST', '/draft/queue/reorder', ['player_ids' => ['P3', 'P1', 'P2']], $mgr);

        $this->assertSame(['P3', 'P1', 'P2'], $this->queueOf($teams[0][0]));
    }

    public function testQueuesArePrivatePerManager(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->seedPlayer('P2');
        $this->finalizeDraft($teams);

        $this->dispatch('POST', '/draft/queue/add', ['player_id' => 'P1'], $this->manager($teams[0][1]));
        $this->dispatch('POST', '/draft/queue/add', ['player_id' => 'P2'], $this->manager($teams[1][1]));

        $this->assertSame(['P1'], $this->queueOf($teams[0][0]));
        $this->assertSame(['P2'], $this->queueOf($teams[1][0]));
    }

    public function testCommissionerWithoutATeamCannotQueue(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->finalizeDraft($teams);

        $response = $this->dispatch('POST', '/draft/queue/add', ['player_id' => 'P1'], $this->commissioner());

        $this->assertSame(403, $response->status);
    }
}
