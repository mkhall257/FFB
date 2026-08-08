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
 * Exercises the Commissioner's live-Draft controls through the HTTP seam:
 * pause/resume, add time, and pick-on-behalf.
 */
final class DraftCommishControlsHttpTest extends DatabaseTestCase
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

    private function seedPlayer(string $id, string $position = 'RB', int $rank = 10): void
    {
        (new PlayerRepository($this->pdo))->upsert($id, null, "Player {$id}", $position, 'KC', 'Active', $rank);
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
     * @param list<array{0:int,1:int}> $teams
     */
    private function startDraft(array $teams): void
    {
        $order = array_map(static fn ($t) => $t[0], $teams);
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $order]);
        $this->dispatch('POST', '/admin/draft/finalize');
        $this->dispatch('POST', '/admin/draft/start');
    }

    private function draftRow(): array
    {
        /** @var array<string,mixed> $row */
        $row = $this->pdo->query('SELECT * FROM drafts')->fetch();

        return $row;
    }

    private function pickRow(int $overall): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM draft_picks WHERE overall_pick = ?');
        $stmt->execute([$overall]);

        /** @var array<string,mixed> $row */
        $row = $stmt->fetch();

        return $row;
    }

    public function testPauseFreezesTheDraftAndBlocksExpiryAutopick(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->startDraft($teams);

        $response = $this->dispatch('POST', '/admin/draft/pause');
        $this->assertSame(302, $response->status);
        $this->assertSame('paused', $this->draftRow()['state']);

        // Even with a past deadline, a paused draft does not auto-pick on poll.
        $this->pdo->exec("UPDATE drafts SET current_deadline = '2000-01-01 00:00:00'");
        $this->dispatch('GET', '/draft');
        $this->assertNull($this->pickRow(1)['player_id']);
    }

    public function testResumeReturnsToLiveWithAFutureDeadline(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->startDraft($teams);
        $this->dispatch('POST', '/admin/draft/pause');

        $response = $this->dispatch('POST', '/admin/draft/resume');
        $this->assertSame(302, $response->status);

        $draft = $this->draftRow();
        $this->assertSame('live', $draft['state']);
        $this->assertNotNull($draft['current_deadline']);
        $this->assertGreaterThan(time(), strtotime((string) $draft['current_deadline']));
    }

    public function testAddTimeExtendsTheDeadline(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->startDraft($teams);

        $before = strtotime((string) $this->draftRow()['current_deadline']);
        $response = $this->dispatch('POST', '/admin/draft/add-time', ['seconds' => '60']);
        $after = strtotime((string) $this->draftRow()['current_deadline']);

        $this->assertSame(302, $response->status);
        $this->assertGreaterThanOrEqual($before + 60, $after);
    }

    public function testPickOnBehalfDraftsForTheTeamOnTheClock(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->startDraft($teams);

        $response = $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'P1']);
        $this->assertSame(302, $response->status);

        $pick = $this->pickRow(1);
        $this->assertSame('P1', $pick['player_id']);
        $this->assertSame('commissioner', $pick['source']);
        $this->assertSame(2, (int) $this->draftRow()['current_pick_no']);
    }

    public function testManagerCannotUseCommissionerControls(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->startDraft($teams);

        $response = $this->dispatch('POST', '/admin/draft/pause', [], $this->manager($teams[0][1]));

        $this->assertSame(403, $response->status);
        $this->assertSame('live', $this->draftRow()['state']);
    }

    public function testEnablingAutoDraftForOnClockTeamPicksImmediately(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('BEST', 'RB', 1);
        $this->startDraft($teams);

        $response = $this->dispatch('POST', '/admin/draft/auto-draft', [
            'team_id' => $teams[0][0], 'enabled' => '1',
        ]);

        $this->assertSame(302, $response->status);
        $this->assertSame('BEST', $this->pickRow(1)['player_id']);
        $this->assertSame('auto', $this->pickRow(1)['source']);
        $this->assertSame(2, (int) $this->draftRow()['current_pick_no']);
    }

    public function testCorrectPickReplacesPlayerWithoutChangingTurn(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->seedPlayer('P2');
        $this->startDraft($teams);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'P1']);

        $response = $this->dispatch('POST', '/admin/draft/correct-pick', [
            'overall_pick' => '1', 'player_id' => 'P2',
        ]);

        $this->assertSame(302, $response->status);
        $this->assertSame('P2', $this->pickRow(1)['player_id']);
        $this->assertSame(2, (int) $this->draftRow()['current_pick_no'], 'turn order is unchanged');
    }

    public function testCorrectPickRejectsAnAlreadyTakenPlayer(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->seedPlayer('P2');
        $this->startDraft($teams);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'P1']);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'P2']);

        // Try to change pick 1 to P2, which pick 2 already holds.
        $response = $this->dispatch('POST', '/admin/draft/correct-pick', [
            'overall_pick' => '1', 'player_id' => 'P2',
        ]);

        $this->assertSame(409, $response->status);
        $this->assertSame('P1', $this->pickRow(1)['player_id']);
    }

    public function testUndoLastRevertsTheMostRecentPick(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->seedPlayer('P2');
        $this->startDraft($teams);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'P1']);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'P2']);

        $response = $this->dispatch('POST', '/admin/draft/undo-last');

        $this->assertSame(302, $response->status);
        $this->assertNull($this->pickRow(2)['player_id']);
        $this->assertSame(2, (int) $this->draftRow()['current_pick_no']);
        $this->assertSame('live', $this->draftRow()['state']);
    }

    public function testUndoLastReopensACompletedDraft(): void
    {
        // Tiny roster: 1 QB, no bench => 1 round. Two teams => 2 picks total.
        $teams = $this->makeManagedTeams(2);
        $this->seedPlayer('Q1', 'QB', 1);
        $this->seedPlayer('Q2', 'QB', 2);
        $this->dispatch('POST', '/admin/draft/config', [
            'pick_seconds' => '120', 'autopick_on_expiry' => '0',
            'roster_qb' => '1', 'roster_rb' => '0', 'roster_wr' => '0', 'roster_te' => '0',
            'roster_flex' => '0', 'roster_k' => '0', 'roster_def' => '0', 'roster_bench' => '0',
        ]);
        $this->startDraft($teams);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q1']);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q2']);
        $this->assertSame('complete', $this->draftRow()['state']);

        $this->dispatch('POST', '/admin/draft/undo-last');

        $draft = $this->draftRow();
        $this->assertSame('live', $draft['state'], 'undoing the last pick reopens a completed draft');
        $this->assertSame(2, (int) $draft['current_pick_no']);
        $this->assertNull($this->pickRow(2)['player_id']);
    }

    public function testResetWipesTheBoardAndReturnsToSetup(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1');
        $this->startDraft($teams);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'P1']);

        $response = $this->dispatch('POST', '/admin/draft/reset');

        $this->assertSame(302, $response->status);
        $draft = $this->draftRow();
        $this->assertSame('setup', $draft['state']);
        $this->assertNull($draft['current_pick_no']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM draft_picks')->fetchColumn());
    }
}
