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
 * Exercises Auto-pick on timer expiry through the HTTP seam: the Commissioner
 * toggle, queue-first selection, the global Sleeper-rank fallback, and the
 * legal-lineup guarantee (see ADR-0007). Expiry is driven by setting the
 * stored pick deadline into the past, then polling the room (no wall-clock).
 */
final class DraftAutoPickHttpTest extends DatabaseTestCase
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

    private function seedPlayer(string $id, string $position, int $rank): void
    {
        (new PlayerRepository($this->pdo))->upsert($id, null, "Player {$id}", $position, 'KC', 'Active', $rank);
    }

    /**
     * @param array<string,mixed> $post
     */
    private function dispatch(string $method, string $path, array $post, ArraySession $session): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $session);
    }

    /**
     * @param array<string,mixed> $config extra config fields (roster shape, toggle)
     * @param list<array{0:int,1:int}> $teams
     */
    private function configureAndStart(array $teams, array $config): void
    {
        $this->dispatch('POST', '/admin/draft/config', $config, $this->commissioner());
        $order = array_map(static fn ($t) => $t[0], $teams);
        $this->dispatch('POST', '/admin/draft/order', ['team_ids' => $order], $this->commissioner());
        $this->dispatch('POST', '/admin/draft/finalize', [], $this->commissioner());
        $this->dispatch('POST', '/admin/draft/start', [], $this->commissioner());
    }

    private function expireCurrentPick(): void
    {
        $this->pdo->exec("UPDATE drafts SET current_deadline = '2000-01-01 00:00:00' WHERE current_pick_no IS NOT NULL");
    }

    private function pollRoom(): void
    {
        $this->dispatch('GET', '/draft', [], $this->commissioner());
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

    /** @return array<string,int> position => count for a team's made picks */
    private function rosterPositions(int $teamId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.position, COUNT(*) c FROM draft_picks dp'
            . ' JOIN players p ON p.sleeper_id = dp.player_id'
            . ' WHERE dp.team_id = ? AND dp.player_id IS NOT NULL GROUP BY p.position'
        );
        $stmt->execute([$teamId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string) $row['position']] = (int) $row['c'];
        }

        return $out;
    }

    private function standardConfig(bool $autopick): array
    {
        return [
            'pick_seconds' => '120',
            'autopick_on_expiry' => $autopick ? '1' : '0',
            'roster_qb' => '1', 'roster_rb' => '2', 'roster_wr' => '2', 'roster_te' => '1',
            'roster_flex' => '1', 'roster_k' => '1', 'roster_def' => '1', 'roster_bench' => '5',
        ];
    }

    public function testExpiryDraftsTheTopAvailableQueuedPlayer(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('TOP', 'RB', 1);
        $this->seedPlayer('Q1', 'RB', 2);
        $this->configureAndStart($teams, $this->standardConfig(true));

        // Team 1 queues Q1 (not the globally top-ranked TOP).
        $this->dispatch('POST', '/draft/queue/add', ['player_id' => 'Q1'], $this->manager($teams[0][1]));

        $this->expireCurrentPick();
        $this->pollRoom();

        $this->assertSame('Q1', $this->pickRow(1)['player_id']);
        $this->assertSame('auto', $this->pickRow(1)['source']);
        $this->assertSame(2, $this->currentPickNo());
    }

    public function testExpiryFallsBackToBestSleeperRankWhenQueueEmpty(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('BEST', 'WR', 1);
        $this->seedPlayer('MEH', 'WR', 99);
        $this->configureAndStart($teams, $this->standardConfig(true));

        $this->expireCurrentPick();
        $this->pollRoom();

        $this->assertSame('BEST', $this->pickRow(1)['player_id']);
        $this->assertSame('auto', $this->pickRow(1)['source']);
    }

    public function testExpiryWithToggleOffLeavesTeamOnTheClock(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('BEST', 'WR', 1);
        $this->configureAndStart($teams, $this->standardConfig(false));

        $this->expireCurrentPick();
        $this->pollRoom();

        $this->assertNull($this->pickRow(1)['player_id'], 'nothing should be auto-picked when the toggle is off');
        $this->assertSame(1, $this->currentPickNo(), 'the team stays on the clock');
    }

    public function testAutoPickGuaranteesRequiredSlotsWithASmallRoster(): void
    {
        // Roster shape: exactly 1 QB + 1 K = 2 rounds. With two teams, danger
        // zone is active from the first pick, so each team must end with one QB
        // and one K rather than two QBs.
        $teams = $this->makeManagedTeams(2);
        foreach (['Q1' => 1, 'Q2' => 2, 'Q3' => 3, 'Q4' => 4] as $id => $rank) {
            $this->seedPlayer($id, 'QB', $rank);
        }
        foreach (['K1' => 50, 'K2' => 51] as $id => $rank) {
            $this->seedPlayer($id, 'K', $rank);
        }

        $config = [
            'pick_seconds' => '120', 'autopick_on_expiry' => '1',
            'roster_qb' => '1', 'roster_rb' => '0', 'roster_wr' => '0', 'roster_te' => '0',
            'roster_flex' => '0', 'roster_k' => '1', 'roster_def' => '0', 'roster_bench' => '0',
        ];
        $this->configureAndStart($teams, $config);

        // Auto-pick the entire draft via repeated expiry polls (4 picks total).
        for ($i = 0; $i < 4; $i++) {
            $this->expireCurrentPick();
            $this->pollRoom();
        }

        $this->assertNull($this->currentPickNo(), 'draft should be complete');
        $this->assertSame(['K' => 1, 'QB' => 1], $this->normalize($this->rosterPositions($teams[0][0])));
        $this->assertSame(['K' => 1, 'QB' => 1], $this->normalize($this->rosterPositions($teams[1][0])));
    }

    /**
     * @param array<string,int> $positions
     * @return array<string,int>
     */
    private function normalize(array $positions): array
    {
        ksort($positions);

        return $positions;
    }
}
