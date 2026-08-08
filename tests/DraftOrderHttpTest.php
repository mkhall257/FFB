<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Exercises Commissioner Draft order-setting and finalization (Setup -> Ready)
 * through the HTTP seam.
 */
final class DraftOrderHttpTest extends DatabaseTestCase
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
            'user_id' => 1,
            'role' => 'commissioner',
            'league_id' => $this->leagueId(),
            'display_name' => 'Boss',
        ]);
    }

    /**
     * @return list<int> the created team ids, in creation order
     */
    private function makeTeams(int $count): array
    {
        $teams = new TeamRepository($this->pdo);
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $teams->create($this->leagueId(), $this->seasonId(), "Team {$i}");
        }

        return $ids;
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
     * @return list<array<string,mixed>>
     */
    private function orderRows(): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->pdo->query('SELECT * FROM draft_order ORDER BY position')->fetchAll();

        return $rows;
    }

    private function draftState(): ?string
    {
        $value = $this->pdo->query('SELECT state FROM drafts')->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function testRandomizeAssignsEveryTeamAPosition(): void
    {
        $ids = $this->makeTeams(4);

        [$response] = $this->dispatch('POST', '/admin/draft/order/randomize');

        $this->assertSame(302, $response->status);

        $rows = $this->orderRows();
        $this->assertCount(4, $rows);
        $this->assertSame([1, 2, 3, 4], array_map(static fn ($r) => (int) $r['position'], $rows));
        $this->assertEqualsCanonicalizing($ids, array_map(static fn ($r) => (int) $r['team_id'], $rows));
    }

    public function testManualReorderStoresTheExactSequence(): void
    {
        $ids = $this->makeTeams(4);
        $desired = [$ids[2], $ids[0], $ids[3], $ids[1]];

        [$response] = $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $desired]);

        $this->assertSame(302, $response->status);
        $this->assertSame($desired, array_map(static fn ($r) => (int) $r['team_id'], $this->orderRows()));
    }

    public function testReorderRejectsAnIncompleteSequence(): void
    {
        $ids = $this->makeTeams(4);

        // Missing one team.
        [$response] = $this->dispatch('POST', '/admin/draft/order', ['team_ids' => [$ids[0], $ids[1], $ids[2]]]);

        $this->assertSame(400, $response->status);
        $this->assertCount(0, $this->orderRows());
    }

    public function testFinalizeMovesDraftToReady(): void
    {
        $this->makeTeams(4);
        $this->dispatch('POST', '/admin/draft/order/randomize');

        [$response] = $this->dispatch('POST', '/admin/draft/finalize');

        $this->assertSame(302, $response->status);
        $this->assertSame('ready', $this->draftState());
    }

    public function testCannotFinalizeWithoutAnOrder(): void
    {
        $this->makeTeams(4);

        [$response] = $this->dispatch('POST', '/admin/draft/finalize');

        $this->assertSame(400, $response->status);
        $this->assertSame('setup', $this->draftState());
    }

    public function testCannotRandomizeOnceReady(): void
    {
        $this->makeTeams(4);
        $this->dispatch('POST', '/admin/draft/order/randomize');
        $this->dispatch('POST', '/admin/draft/finalize');

        [$response] = $this->dispatch('POST', '/admin/draft/order/randomize');

        $this->assertSame(409, $response->status);
        $this->assertSame('ready', $this->draftState());
    }

    public function testManagerCannotSetOrder(): void
    {
        $this->makeTeams(4);
        $manager = new ArraySession([
            'user_id' => 9, 'role' => 'manager', 'league_id' => $this->leagueId(), 'display_name' => 'Kid',
        ]);

        [$response] = $this->dispatch('POST', '/admin/draft/order/randomize', [], $manager);

        $this->assertSame(403, $response->status);
        $this->assertCount(0, $this->orderRows());
    }
}
