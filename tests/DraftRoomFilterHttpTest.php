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
 * Exercises the draft-room availability filters: position (which surfaces the
 * low-volume K/DEF slots that the default rank-ordered limit would otherwise
 * bury) and name search, both at the repository seam and rendered in the room.
 */
final class DraftRoomFilterHttpTest extends DatabaseTestCase
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

    private function seedPlayer(string $id, string $name, string $position, ?int $rank): void
    {
        (new PlayerRepository($this->pdo))->upsert($id, null, $name, $position, 'KC', 'Active', $rank);
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $query
     */
    private function dispatch(string $method, string $path, array $post = [], array $query = [], ?ArraySession $session = null): Response
    {
        $session ??= $this->commissioner();

        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post, $query), $session);
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

    private function draftId(): int
    {
        return (int) $this->pdo->query('SELECT id FROM drafts')->fetchColumn();
    }

    public function testPositionFilterSurfacesDefensesTheDefaultLimitBuries(): void
    {
        // Two top-ranked skill players and one team defense.
        $this->seedPlayer('QB1', 'Star Quarterback', 'QB', 1);
        $this->seedPlayer('RB1', 'Star Runningback', 'RB', 2);
        $this->seedPlayer('DST1', 'KC Defense', 'DEF', 500);
        $players = new PlayerRepository($this->pdo);
        $draftId = 999; // no picks, so nothing is "taken"

        // With a tight limit (mimicking the rank cutoff), the defense is buried.
        $topTwo = $players->availableForDraft($draftId, null, null, 2);
        $ids = array_column($topTwo, 'sleeper_id');
        $this->assertNotContains('DST1', $ids, 'defense is below the ranked skill players');

        // Filtering to DEF surfaces it regardless of the limit.
        $defenses = $players->availableForDraft($draftId, null, 'DEF', 2);
        $this->assertSame(['DST1'], array_column($defenses, 'sleeper_id'));
    }

    public function testSearchFindsPlayerByName(): void
    {
        $this->seedPlayer('P1', 'Patrick Mahomes', 'QB', 1);
        $this->seedPlayer('P2', 'Josh Allen', 'QB', 2);
        $players = new PlayerRepository($this->pdo);

        $hits = $players->availableForDraft(999, 'mahom', null);
        $this->assertSame(['P1'], array_column($hits, 'sleeper_id'));
    }

    public function testSearchFindsDefenseByTeam(): void
    {
        // Defenses are found by their NFL team as well as their name.
        (new PlayerRepository($this->pdo))->upsert('DST_SF', null, 'San Francisco Defense', 'DEF', 'SF', 'Active', 300);
        (new PlayerRepository($this->pdo))->upsert('QB_KC', null, 'Patrick Mahomes', 'QB', 'KC', 'Active', 1);
        $players = new PlayerRepository($this->pdo);

        $byTeam = $players->availableForDraft(999, 'SF', null);
        $this->assertSame(['DST_SF'], array_column($byTeam, 'sleeper_id'));

        $byName = $players->availableForDraft(999, 'francisco', null);
        $this->assertSame(['DST_SF'], array_column($byName, 'sleeper_id'));
    }

    public function testOmittingTheLimitReturnsTheWholePool(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedPlayer("P{$i}", "Player {$i}", 'WR', $i);
        }
        $players = new PlayerRepository($this->pdo);

        // No limit: every available player comes back.
        $this->assertCount(5, $players->availableForDraft(999));
        // An explicit limit still caps, for callers that want it.
        $this->assertCount(2, $players->availableForDraft(999, null, null, 2));
    }

    public function testRoomPositionFilterShowsDefensesAndHidesOthers(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('QB1', 'Zeus Quarterback', 'QB', 1);
        $this->seedPlayer('DST1', 'Falcons Defense', 'DEF', 400);
        $this->startDraft($teams);

        $response = $this->dispatch('GET', '/draft', [], ['pos' => 'DEF'], $this->manager($teams[1][1]));

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Falcons Defense', $response->body);
        $this->assertStringNotContainsString('Zeus Quarterback', $response->body);
    }

    public function testRoomSearchNarrowsTheList(): void
    {
        $teams = $this->makeManagedTeams(4);
        $this->seedPlayer('P1', 'Unique Searchname', 'WR', 1);
        $this->seedPlayer('P2', 'Someone Else', 'WR', 2);
        $this->startDraft($teams);

        $response = $this->dispatch('GET', '/draft', [], ['q' => 'Searchname'], $this->manager($teams[1][1]));

        $this->assertStringContainsString('Unique Searchname', $response->body);
        $this->assertStringNotContainsString('Someone Else', $response->body);
    }
}
