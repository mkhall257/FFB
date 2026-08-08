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
 * Exercises making a pick during a live Draft through the HTTP seam: turn
 * enforcement, Player availability, and advancing the clock.
 */
final class DraftPickHttpTest extends DatabaseTestCase
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
        return new ArraySession([
            'user_id' => 1, 'role' => 'commissioner',
            'league_id' => $this->leagueId(), 'display_name' => 'Boss',
        ]);
    }

    /**
     * Create $count teams, each with a Manager login. Returns list of
     * [team_id, user_id] in draft order.
     *
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

    private function seedPlayer(string $id, string $position = 'QB'): void
    {
        (new PlayerRepository($this->pdo))->upsert($id, null, "Player {$id}", $position, 'KC', 'Active', 10);
    }

    /**
     * @param array<string,mixed> $post
     * @return array{0:Response,1:ArraySession}
     */
    private function dispatch(string $method, string $path, array $post = [], ?ArraySession $session = null): array
    {
        $session ??= $this->commissioner();
        $response = Kernel::router($this->pdo)
            ->dispatch(new Request($method, $path, $post), $session);

        return [$response, $session];
    }

    /**
     * @param list<array{0:int,1:int}> $teams [team_id, user_id] in order
     */
    private function startDraftWith(array $teams): void
    {
        $order = array_map(static fn ($t) => $t[0], $teams);
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $order]);
        $this->dispatch('POST', '/admin/draft/finalize');
        $this->dispatch('POST', '/admin/draft/start');
    }

    private function pickRow(int $overall): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM draft_picks WHERE overall_pick = ?');
        $stmt->execute([$overall]);

        /** @var array<string,mixed> $row */
        $row = $stmt->fetch();

        return $row;
    }

    private function currentPickNo(): ?int
    {
        $value = $this->pdo->query('SELECT current_pick_no FROM drafts')->fetchColumn();

        return $value === null || $value === false ? null : (int) $value;
    }

    public function testTeamOnTheClockCanDraftAnAvailablePlayer(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->startDraftWith($teams);

        [$response] = $this->dispatch('POST', '/draft/pick', ['player_id' => 'P1'], $this->manager($teams[0][1]));

        $this->assertSame(302, $response->status);

        $pick = $this->pickRow(1);
        $this->assertSame('P1', $pick['player_id']);
        $this->assertSame('manual', $pick['source']);
        $this->assertNotNull($pick['picked_at']);
        $this->assertSame(2, $this->currentPickNo());
    }

    public function testCannotDraftOutOfTurn(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->startDraftWith($teams);

        // Team 2's manager tries to pick while Team 1 is on the clock.
        [$response] = $this->dispatch('POST', '/draft/pick', ['player_id' => 'P1'], $this->manager($teams[1][1]));

        $this->assertSame(403, $response->status);
        $this->assertNull($this->pickRow(1)['player_id']);
        $this->assertSame(1, $this->currentPickNo());
    }

    public function testCannotDraftAnAlreadyTakenPlayer(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->seedPlayer('P2');
        $this->startDraftWith($teams);

        $this->dispatch('POST', '/draft/pick', ['player_id' => 'P1'], $this->manager($teams[0][1]));
        // Now Team 2 is on the clock; try to take the same Player.
        [$response] = $this->dispatch('POST', '/draft/pick', ['player_id' => 'P1'], $this->manager($teams[1][1]));

        $this->assertSame(409, $response->status);
        $this->assertNull($this->pickRow(2)['player_id']);
        $this->assertSame(2, $this->currentPickNo());
    }

    public function testCannotDraftAnUnknownPlayer(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->startDraftWith($teams);

        [$response] = $this->dispatch('POST', '/draft/pick', ['player_id' => 'NOPE'], $this->manager($teams[0][1]));

        $this->assertSame(400, $response->status);
        $this->assertSame(1, $this->currentPickNo());
    }

    public function testCannotDraftABeforeTheDraftIsLive(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        // Finalize but do NOT start.
        $order = array_map(static fn ($t) => $t[0], $teams);
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $order]);
        $this->dispatch('POST', '/admin/draft/finalize');

        [$response] = $this->dispatch('POST', '/draft/pick', ['player_id' => 'P1'], $this->manager($teams[0][1]));

        $this->assertSame(409, $response->status);
    }
}
