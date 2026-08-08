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
 * Exercises starting the Draft (Ready -> Live), which generates the full snake
 * pick board and puts the first Team on the clock.
 */
final class DraftStartHttpTest extends DatabaseTestCase
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
     * @return list<int>
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

    private function draftRow(): array
    {
        /** @var array<string,mixed> $row */
        $row = $this->pdo->query('SELECT * FROM drafts')->fetch();

        return $row;
    }

    /**
     * @return list<int> team_id per overall pick, in overall order
     */
    private function boardTeamSequence(): array
    {
        /** @var list<int> $ids */
        $ids = $this->pdo
            ->query('SELECT team_id FROM draft_picks ORDER BY overall_pick')
            ->fetchAll(\PDO::FETCH_COLUMN);

        return array_map('intval', $ids);
    }

    /**
     * @param list<int> $order
     */
    private function readyDraft(array $order): void
    {
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $order]);
        $this->dispatch('POST', '/admin/draft/finalize');
    }

    public function testStartGeneratesSnakeBoardAndGoesLive(): void
    {
        $ids = $this->makeTeams(4);
        $this->readyDraft($ids);

        [$response] = $this->dispatch('POST', '/admin/draft/start');
        $this->assertSame(302, $response->status);

        $draft = $this->draftRow();
        $this->assertSame('live', $draft['state']);
        $this->assertSame(1, (int) $draft['current_pick_no']);
        $this->assertNotNull($draft['current_deadline']);
        $this->assertNotNull($draft['started_at']);

        // 14 rounds (9 starters + 5 bench) x 4 teams = 56 picks.
        $sequence = $this->boardTeamSequence();
        $this->assertCount(56, $sequence);

        // Round 1 follows the order; round 2 reverses it (snake).
        $this->assertSame([$ids[0], $ids[1], $ids[2], $ids[3]], array_slice($sequence, 0, 4));
        $this->assertSame([$ids[3], $ids[2], $ids[1], $ids[0]], array_slice($sequence, 4, 4));
        // Round 3 forward again.
        $this->assertSame([$ids[0], $ids[1], $ids[2], $ids[3]], array_slice($sequence, 8, 4));
    }

    public function testFirstPickIsAssignedToTheFirstTeamInOrder(): void
    {
        $ids = $this->makeTeams(4);
        $this->readyDraft($ids);
        $this->dispatch('POST', '/admin/draft/start');

        $firstTeam = (int) $this->pdo
            ->query('SELECT team_id FROM draft_picks WHERE overall_pick = 1')
            ->fetchColumn();

        $this->assertSame($ids[0], $firstTeam);
    }

    public function testCannotStartUnlessReady(): void
    {
        $ids = $this->makeTeams(4);
        // Order set but NOT finalized: still in setup.
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $ids]);

        [$response] = $this->dispatch('POST', '/admin/draft/start');

        $this->assertSame(409, $response->status);
        $this->assertSame('setup', $this->draftRow()['state']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM draft_picks')->fetchColumn());
    }
}
