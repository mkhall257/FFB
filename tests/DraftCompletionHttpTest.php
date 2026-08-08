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
 * Exercises Draft completion: the last pick writes each Team's Season Roster,
 * and undo/reset roll that back.
 */
final class DraftCompletionHttpTest extends DatabaseTestCase
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
     * @return list<int> team ids
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

    private function seedPlayer(string $id, string $position = 'QB', int $rank = 10): void
    {
        (new PlayerRepository($this->pdo))->upsert($id, null, "Player {$id}", $position, 'KC', 'Active', $rank);
    }

    /**
     * @param array<string,mixed> $post
     */
    private function dispatch(string $method, string $path, array $post = []): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $this->commissioner());
    }

    /**
     * One-round draft (1 QB, no bench) with the given teams, started.
     *
     * @param list<int> $teamIds
     */
    private function startOneRoundDraft(array $teamIds): void
    {
        $this->dispatch('POST', '/admin/draft/config', [
            'pick_seconds' => '120', 'autopick_on_expiry' => '0',
            'roster_qb' => '1', 'roster_rb' => '0', 'roster_wr' => '0', 'roster_te' => '0',
            'roster_flex' => '0', 'roster_k' => '0', 'roster_def' => '0', 'roster_bench' => '0',
        ]);
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $teamIds]);
        $this->dispatch('POST', '/admin/draft/finalize');
        $this->dispatch('POST', '/admin/draft/start');
    }

    private function rosterCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM rosters')->fetchColumn();
    }

    private function draftState(): string
    {
        return (string) $this->pdo->query('SELECT state FROM drafts')->fetchColumn();
    }

    public function testCompletingTheDraftWritesEachTeamsRoster(): void
    {
        $teams = $this->makeTeams(2);
        $this->seedPlayer('Q1');
        $this->seedPlayer('Q2');
        $this->startOneRoundDraft($teams);

        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q1']);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q2']);

        $this->assertSame('complete', $this->draftState());
        $this->assertSame(2, $this->rosterCount());

        // Team 1 (first in order) drafted Q1; team 2 drafted Q2.
        $stmt = $this->pdo->prepare('SELECT player_id FROM rosters WHERE team_id = ?');
        $stmt->execute([$teams[0]]);
        $this->assertSame('Q1', (string) $stmt->fetchColumn());
    }

    public function testUndoAfterCompletionReopensAndClearsRosters(): void
    {
        $teams = $this->makeTeams(2);
        $this->seedPlayer('Q1');
        $this->seedPlayer('Q2');
        $this->startOneRoundDraft($teams);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q1']);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q2']);

        $this->dispatch('POST', '/admin/draft/undo-last');

        $this->assertSame('live', $this->draftState());
        $this->assertSame(0, $this->rosterCount(), 'reopening the draft clears the materialized rosters');
    }

    public function testResetClearsRosters(): void
    {
        $teams = $this->makeTeams(2);
        $this->seedPlayer('Q1');
        $this->seedPlayer('Q2');
        $this->startOneRoundDraft($teams);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q1']);
        $this->dispatch('POST', '/admin/draft/pick-on-behalf', ['player_id' => 'Q2']);
        $this->assertSame(2, $this->rosterCount());

        $this->dispatch('POST', '/admin/draft/reset');

        $this->assertSame('setup', $this->draftState());
        $this->assertSame(0, $this->rosterCount());
    }
}
