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
 * How Add/Drop interacts with the weekly Lineup lock (ADR-0008/0010): dropping a
 * Player in an un-locked week clears their Lineup slot; in a locked week the
 * snapshot stays and keeps scoring, while Roster membership still changes.
 */
final class TransactionLineupHttpTest extends DatabaseTestCase
{
    private int $leagueId;
    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
        // cap = 1 (single QB), current week 1.
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'roster.qb' => '1', 'roster.rb' => '0', 'roster.wr' => '0', 'roster.te' => '0',
            'roster.flex' => '0', 'roster.k' => '0', 'roster.def' => '0', 'roster.bench' => '0',
            'schedule.current_week' => '1',
        ]);
        $this->pdo->prepare("INSERT INTO drafts (league_id, season_id, state) VALUES (?,?,'complete')")
            ->execute([$this->leagueId, $this->seasonId]);
    }

    public function testDroppingAStarterInAnUnlockedWeekClearsTheSlot(): void
    {
        [$teamId, $userId] = $this->team('Sharks');
        $this->roster($teamId, 'OLD', 'QB');
        $this->startInLineup($teamId, 1, 'QB', 0, 'OLD'); // OLD is the week-1 starter
        $this->player('NEW', 'QB');
        // no week_1_kickoff => week is unlocked

        $response = $this->dispatch($userId, 'POST', '/players/add', [
            'add_player_id' => 'NEW', 'drop_player_id' => 'OLD',
        ]);

        $this->assertSame(302, $response->status);
        $this->assertNull($this->slotPlayer($teamId, 1, 'QB', 0)); // slot cleared
    }

    public function testDroppingAStarterInALockedWeekLeavesTheSnapshot(): void
    {
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'schedule.week_1_kickoff' => '2000-01-01T00:00:00-05:00', // in the past => locked
        ]);
        [$teamId, $userId] = $this->team('Bears');
        $this->roster($teamId, 'OLD', 'QB');
        $this->startInLineup($teamId, 1, 'QB', 0, 'OLD');
        $this->player('NEW', 'QB');

        $response = $this->dispatch($userId, 'POST', '/players/add', [
            'add_player_id' => 'NEW', 'drop_player_id' => 'OLD',
        ]);

        $this->assertSame(302, $response->status);
        // Membership changed...
        $this->assertSame($teamId, $this->rosterTeamOf('NEW'));
        $this->assertNull($this->rosterTeamOf('OLD'));
        // ...but the locked snapshot still starts OLD (keeps scoring this week).
        $this->assertSame('OLD', $this->slotPlayer($teamId, 1, 'QB', 0));
    }

    // --- helpers ---

    /** @return array{0:int,1:int} */
    private function team(string $name): array
    {
        $teamId = (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, $name);
        $userId = (new UserRepository($this->pdo))->create($this->leagueId, 'mgr', 'password1', 'manager', $name);
        (new TeamRepository($this->pdo))->assignManager($teamId, $userId);

        return [$teamId, $userId];
    }

    private function player(string $id, string $pos): void
    {
        (new PlayerRepository($this->pdo))->upsert($id, null, $id, $pos, 'KC', 'Active', 1);
    }

    private function roster(int $teamId, string $playerId, string $pos): void
    {
        $this->player($playerId, $pos);
        $this->pdo->prepare(
            "INSERT INTO rosters (league_id, season_id, team_id, player_id, acquired) VALUES (?,?,?,?,'draft')"
        )->execute([$this->leagueId, $this->seasonId, $teamId, $playerId]);
    }

    private function startInLineup(int $teamId, int $week, string $slot, int $index, string $playerId): void
    {
        $this->pdo->prepare(
            'INSERT INTO lineups (league_id, season_id, week, team_id, roster_slot, slot_index, player_id)'
            . ' VALUES (?,?,?,?,?,?,?)'
        )->execute([$this->leagueId, $this->seasonId, $week, $teamId, $slot, $index, $playerId]);
    }

    private function slotPlayer(int $teamId, int $week, string $slot, int $index): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT player_id FROM lineups WHERE season_id = ? AND week = ? AND team_id = ? AND roster_slot = ? AND slot_index = ?'
        );
        $stmt->execute([$this->seasonId, $week, $teamId, $slot, $index]);
        $v = $stmt->fetchColumn();

        return $v === false || $v === null ? null : (string) $v;
    }

    private function rosterTeamOf(string $playerId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT team_id FROM rosters WHERE season_id = ? AND player_id = ?');
        $stmt->execute([$this->seasonId, $playerId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
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
