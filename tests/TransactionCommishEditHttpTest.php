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
 * The Commissioner's manual roster-edit escape hatch (ADR-0010): move, add, or
 * drop a Player bypassing the cap/availability/lock rules (but not
 * uq_roster_player), recorded as a reversible commish_edit Transaction.
 */
final class TransactionCommishEditHttpTest extends DatabaseTestCase
{
    private int $leagueId;
    private int $seasonId;
    private int $commishId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
        $this->commishId = (new UserRepository($this->pdo))
            ->create($this->leagueId, 'boss', 'password1', 'commissioner', 'Commish');
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'roster.qb' => '1', 'roster.rb' => '0', 'roster.wr' => '0', 'roster.te' => '0',
            'roster.flex' => '0', 'roster.k' => '0', 'roster.def' => '0', 'roster.bench' => '0',
        ]); // cap = 1
    }

    public function testCommissionerMovesAPlayerBetweenTeams(): void
    {
        $a = $this->team('Sharks');
        $b = $this->team('Bears');
        $this->roster($a, 'P1', 'QB');

        $response = $this->asCommish('POST', '/admin/roster-edit', [
            'player_id' => 'P1', 'to_team_id' => (string) $b,
        ]);

        $this->assertSame(302, $response->status);
        $this->assertSame($b, $this->rosterTeamOf('P1'));
        $this->assertSame('trade', $this->acquiredOf('P1'));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM transactions WHERE type='commish_edit'")->fetchColumn());
    }

    public function testCommissionerDropsAPlayerToThePool(): void
    {
        $a = $this->team('Sharks');
        $this->roster($a, 'P1', 'QB');

        $this->asCommish('POST', '/admin/roster-edit', ['player_id' => 'P1', 'to_team_id' => '']);

        $this->assertNull($this->rosterTeamOf('P1'));
    }

    public function testCommissionerAddsAFreeAgentToATeam(): void
    {
        $a = $this->team('Sharks');
        (new PlayerRepository($this->pdo))->upsert('FA', null, 'FA', 'QB', 'KC', 'Active', 1);

        $this->asCommish('POST', '/admin/roster-edit', ['player_id' => 'FA', 'to_team_id' => (string) $a]);

        $this->assertSame($a, $this->rosterTeamOf('FA'));
        $this->assertSame('add', $this->acquiredOf('FA'));
    }

    public function testManualEditBypassesTheRosterCap(): void
    {
        $a = $this->team('Sharks');
        $this->roster($a, 'P1', 'QB'); // team at cap (1)
        (new PlayerRepository($this->pdo))->upsert('P2', null, 'P2', 'QB', 'KC', 'Active', 2);

        $response = $this->asCommish('POST', '/admin/roster-edit', ['player_id' => 'P2', 'to_team_id' => (string) $a]);

        $this->assertSame(302, $response->status);
        $this->assertSame($a, $this->rosterTeamOf('P2')); // added despite being over cap
        $this->assertSame(2, $this->sizeOf($a));
    }

    public function testManualEditIsReversible(): void
    {
        $a = $this->team('Sharks');
        $b = $this->team('Bears');
        $this->roster($a, 'P1', 'QB');
        $this->asCommish('POST', '/admin/roster-edit', ['player_id' => 'P1', 'to_team_id' => (string) $b]);
        $txnId = (int) $this->pdo->query('SELECT MAX(id) FROM transactions')->fetchColumn();

        $this->asCommish('POST', '/admin/transactions/reverse', ['transaction_id' => (string) $txnId]);

        $this->assertSame($a, $this->rosterTeamOf('P1')); // back on Sharks
        $this->assertSame('draft', $this->acquiredOf('P1')); // prior acquired restored
    }

    public function testManagerCannotUseRosterEdit(): void
    {
        $a = $this->team('Sharks');
        $this->roster($a, 'P1', 'QB');
        $mgr = (new UserRepository($this->pdo))->create($this->leagueId, 'm', 'password1', 'manager', 'M');

        $session = new ArraySession([
            'user_id' => $mgr, 'role' => 'manager',
            'league_id' => $this->leagueId, 'display_name' => 'M',
        ]);
        $response = Kernel::router($this->pdo)->dispatch(
            new Request('POST', '/admin/roster-edit', ['player_id' => 'P1', 'to_team_id' => '']),
            $session,
        );

        $this->assertSame(403, $response->status);
        $this->assertSame($a, $this->rosterTeamOf('P1'));
    }

    // --- helpers ---

    private function team(string $name): int
    {
        return (new TeamRepository($this->pdo))->create($this->leagueId, $this->seasonId, $name);
    }

    private function roster(int $teamId, string $playerId, string $pos): void
    {
        (new PlayerRepository($this->pdo))->upsert($playerId, null, $playerId, $pos, 'KC', 'Active', 1);
        $this->pdo->prepare(
            "INSERT INTO rosters (league_id, season_id, team_id, player_id, acquired) VALUES (?,?,?,?,'draft')"
        )->execute([$this->leagueId, $this->seasonId, $teamId, $playerId]);
    }

    private function rosterTeamOf(string $playerId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT team_id FROM rosters WHERE season_id = ? AND player_id = ?');
        $stmt->execute([$this->seasonId, $playerId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function acquiredOf(string $playerId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT acquired FROM rosters WHERE season_id = ? AND player_id = ?');
        $stmt->execute([$this->seasonId, $playerId]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    private function sizeOf(int $teamId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rosters WHERE season_id = ? AND team_id = ?');
        $stmt->execute([$this->seasonId, $teamId]);

        return (int) $stmt->fetchColumn();
    }

    private function asCommish(string $method, string $path, array $post = []): Response
    {
        $session = new ArraySession([
            'user_id' => $this->commishId, 'role' => 'commissioner',
            'league_id' => $this->leagueId, 'display_name' => 'Commish',
        ]);

        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $session);
    }
}
