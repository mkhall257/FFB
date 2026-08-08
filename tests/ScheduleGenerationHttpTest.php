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

/**
 * Completing the Draft generates the regular-season Schedule; reopening clears it.
 */
final class ScheduleGenerationHttpTest extends DatabaseTestCase
{
    private function commissioner(): ArraySession
    {
        $leagues = new LeagueRepository($this->pdo);

        return new ArraySession([
            'user_id' => 9999, 'role' => 'commissioner',
            'league_id' => $leagues->currentLeagueId(), 'display_name' => 'Boss',
        ]);
    }

    private function dispatch(string $method, string $path, array $post = []): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $this->commissioner());
    }

    private function seasonId(): int
    {
        return (new LeagueRepository($this->pdo))->currentSeasonId();
    }

    private function matchupCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM matchups')->fetchColumn();
    }

    private function completeTwoTeamDraft(): void
    {
        $leagueId = (new LeagueRepository($this->pdo))->currentLeagueId();
        $teams = new TeamRepository($this->pdo);
        $ids = [];
        for ($i = 1; $i <= 2; $i++) {
            $ids[] = $teams->create($leagueId, $this->seasonId(), "Team {$i}");
        }
        (new PlayerRepository($this->pdo))->upsert('Q1', null, 'Q One', 'QB', 'KC', 'Active', 1);
        (new PlayerRepository($this->pdo))->upsert('Q2', null, 'Q Two', 'QB', 'KC', 'Active', 2);

        $this->dispatch('POST', '/admin/draft/config', [
            'pick_seconds' => '120', 'autopick_on_expiry' => '0',
            'roster_qb' => '1', 'roster_rb' => '0', 'roster_wr' => '0', 'roster_te' => '0',
            'roster_flex' => '0', 'roster_k' => '0', 'roster_def' => '0', 'roster_bench' => '0',
        ]);
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $ids]);
        $this->dispatch('POST', '/admin/draft/finalize');
        $this->dispatch('POST', '/admin/draft/start');
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q1']);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q2']);
    }

    public function testCompletingDraftGeneratesSchedule(): void
    {
        $this->completeTwoTeamDraft();

        // 2 teams x 14 weeks = 14 matchups (one per week).
        $this->assertSame(14, $this->matchupCount());
    }

    public function testUndoAfterCompletionClearsSchedule(): void
    {
        $this->completeTwoTeamDraft();
        $this->assertSame(14, $this->matchupCount());

        $this->dispatch('POST', '/admin/draft/undo-last');
        $this->assertSame(0, $this->matchupCount());
    }

    public function testResetAfterCompletionClearsSchedule(): void
    {
        $this->completeTwoTeamDraft();
        $this->assertSame(14, $this->matchupCount());

        $this->dispatch('POST', '/admin/draft/reset');
        $this->assertSame(0, $this->matchupCount());
    }
}
