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
 * Commissioner team lifecycle: deactivate/reactivate a team, and hard-delete a
 * team only when it has no league history.
 */
final class AdminTeamLifecycleHttpTest extends DatabaseTestCase
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
            'user_id' => 1, 'role' => 'commissioner', 'league_id' => $this->leagueId(), 'display_name' => 'Boss',
        ]);
    }

    /** @param array<string,mixed> $post */
    private function dispatch(string $method, string $path, array $post = [], ?ArraySession $session = null): Response
    {
        return Kernel::router($this->pdo)->dispatch(
            new Request($method, $path, $post),
            $session ?? $this->commissioner(),
        );
    }

    private function makeTeam(string $name): int
    {
        return (new TeamRepository($this->pdo))->create($this->leagueId(), $this->seasonId(), $name);
    }

    private function teamActive(int $teamId): int
    {
        return (int) $this->pdo->query("SELECT is_active FROM teams WHERE id = {$teamId}")->fetchColumn();
    }

    public function testDeactivateAndReactivateTeam(): void
    {
        $teamId = $this->makeTeam('Sharks');

        $this->dispatch('POST', '/admin/teams/status', ['team_id' => $teamId, 'active' => '0']);
        $this->assertSame(0, $this->teamActive($teamId));

        $this->dispatch('POST', '/admin/teams/status', ['team_id' => $teamId, 'active' => '1']);
        $this->assertSame(1, $this->teamActive($teamId));
    }

    public function testDeleteEmptyTeamRemovesIt(): void
    {
        $teamId = $this->makeTeam('Sharks');

        $response = $this->dispatch('POST', '/admin/teams/delete', ['team_id' => $teamId]);

        $this->assertSame(302, $response->status);
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM teams WHERE id = {$teamId}")->fetchColumn(),
        );
    }

    public function testDeleteTeamWithHistoryIsBlocked(): void
    {
        $sharks = $this->makeTeam('Sharks');
        $bears = $this->makeTeam('Bears');
        // A matchup gives the Sharks league history, so deleting must be refused.
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, home_team_id, away_team_id, status)'
            . " VALUES (?,?,1,?,?,'scheduled')"
        )->execute([$this->leagueId(), $this->seasonId(), $sharks, $bears]);

        $response = $this->dispatch('POST', '/admin/teams/delete', ['team_id' => $sharks]);

        $this->assertSame(400, $response->status);
        $this->assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM teams WHERE id = {$sharks}")->fetchColumn(),
            'a team with history must survive a delete attempt',
        );
    }

    public function testManagerCannotDeleteTeam(): void
    {
        $teamId = $this->makeTeam('Sharks');
        $manager = new ArraySession([
            'user_id' => 9, 'role' => 'manager', 'league_id' => $this->leagueId(), 'display_name' => 'Kid',
        ]);

        $response = $this->dispatch('POST', '/admin/teams/delete', ['team_id' => $teamId], $manager);

        $this->assertSame(403, $response->status);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM teams WHERE id = {$teamId}")->fetchColumn());
    }
}
