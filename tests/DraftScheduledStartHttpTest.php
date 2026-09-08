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
use FFB\UserRepository;

/**
 * Exercises the pre-staged auto-start: a finalized Draft with a scheduled
 * date/time goes Live on its own when that time arrives, resolved poll-driven
 * on any relevant page load (and by the cron tick).
 */
final class DraftScheduledStartHttpTest extends DatabaseTestCase
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
            'user_id' => 9999, 'role' => 'commissioner',
            'league_id' => $this->leagueId(), 'display_name' => 'Boss',
        ]);
    }

    private function manager(int $userId): ArraySession
    {
        return new ArraySession([
            'user_id' => $userId, 'role' => 'manager',
            'league_id' => $this->leagueId(), 'display_name' => 'Kid',
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

    /**
     * @param array<string,mixed> $post
     */
    private function dispatch(string $method, string $path, array $post = [], ?ArraySession $session = null): Response
    {
        $session ??= $this->commissioner();

        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $session);
    }

    /**
     * Finalize the Draft (order set, state 'ready') without starting it.
     *
     * @param list<array{0:int,1:int}> $teams
     */
    private function finalizeDraft(array $teams): void
    {
        $order = array_map(static fn ($t) => $t[0], $teams);
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $order]);
        $this->dispatch('POST', '/admin/draft/finalize');
    }

    private function setSchedule(string $sqlDatetime): void
    {
        $this->pdo->prepare('UPDATE drafts SET scheduled_at = ?')->execute([$sqlDatetime]);
    }

    private function draftRow(): array
    {
        /** @var array<string,mixed> $row */
        $row = $this->pdo->query('SELECT * FROM drafts')->fetch();

        return $row;
    }

    public function testDueScheduledStartGoesLiveWhenAManagerOpensTheRoom(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->finalizeDraft($teams);
        $this->setSchedule(date('Y-m-d H:i:s', time() - 60));

        $response = $this->dispatch('GET', '/draft', [], $this->manager($teams[0][1]));

        $this->assertSame(200, $response->status);
        $draft = $this->draftRow();
        $this->assertSame('live', $draft['state']);
        $this->assertSame(1, (int) $draft['current_pick_no']);
        // The board was generated on start.
        $this->assertGreaterThan(0, (int) $this->pdo->query('SELECT COUNT(*) FROM draft_picks')->fetchColumn());
    }

    public function testDueScheduledStartGoesLiveWhenTheCommissionerOpensSetup(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->finalizeDraft($teams);
        $this->setSchedule(date('Y-m-d H:i:s', time() - 5));

        $response = $this->dispatch('GET', '/admin/draft');

        // Redirects into the live room.
        $this->assertSame(302, $response->status);
        $this->assertSame('live', $this->draftRow()['state']);
    }

    public function testFutureScheduleDoesNotStartYet(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->finalizeDraft($teams);
        $this->setSchedule(date('Y-m-d H:i:s', time() + 3600));

        $this->dispatch('GET', '/draft', [], $this->manager($teams[0][1]));

        $this->assertSame('ready', $this->draftRow()['state']);
    }

    public function testDueScheduleDoesNotStartAnUnfinalizedDraft(): void
    {
        // Order set but NOT finalized: still in setup, so the schedule can't fire.
        $teams = $this->makeManagedTeams(4);
        $order = array_map(static fn ($t) => $t[0], $teams);
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $order]);
        $this->setSchedule(date('Y-m-d H:i:s', time() - 60));

        $this->dispatch('GET', '/draft', [], $this->manager($teams[0][1]));

        $this->assertSame('setup', $this->draftRow()['state']);
    }

    public function testManualStartStillWorksAfterTheRefactor(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->finalizeDraft($teams);

        $response = $this->dispatch('POST', '/admin/draft/start');

        $this->assertSame(302, $response->status);
        $this->assertSame('live', $this->draftRow()['state']);
    }
}
