<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\Players\PlayerIdCrosswalk;
use FFB\Players\PlayerImporter;
use FFB\PlayerRepository;
use FFB\PlayerSyncLogRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Exercises the Commissioner Unmatched Players review through the HTTP seam.
 */
final class UnmatchedReviewHttpTest extends DatabaseTestCase
{
    private function seedImportedPlayers(): void
    {
        /** @var array<string,array<string,mixed>> $sleeper */
        $sleeper = json_decode((string) file_get_contents(__DIR__ . '/fixtures/sleeper_players.json'), true);
        $crosswalk = PlayerIdCrosswalk::parse((string) file_get_contents(__DIR__ . '/fixtures/crosswalk.csv'));
        (new PlayerImporter(new PlayerRepository($this->pdo)))->import($sleeper, $crosswalk);
    }

    private function commissioner(): ArraySession
    {
        return new ArraySession([
            'user_id' => 1,
            'role' => 'commissioner',
            'league_id' => (new LeagueRepository($this->pdo))->currentLeagueId(),
            'display_name' => 'Boss',
        ]);
    }

    private function dispatch(ArraySession $session): Response
    {
        return Kernel::router($this->pdo)
            ->dispatch(new Request('GET', '/admin/unmatched-players', []), $session);
    }

    public function testReviewListsUnmatchedPlayersForCommissioner(): void
    {
        $this->seedImportedPlayers();

        $response = $this->dispatch($this->commissioner());

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Undrafted Rookie', $response->body);
        // A linked player must not appear in the Unmatched list.
        $this->assertStringNotContainsString('Pat Testman', $response->body);
    }

    public function testReviewShowsLatestSyncStatus(): void
    {
        $log = new PlayerSyncLogRepository($this->pdo);
        $id = $log->start();
        $log->finishSuccess($id, 6, 1);

        $response = $this->dispatch($this->commissioner());

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('success', $response->body);
    }

    public function testReviewIsForbiddenForManager(): void
    {
        $manager = new ArraySession([
            'user_id' => 9,
            'role' => 'manager',
            'league_id' => (new LeagueRepository($this->pdo))->currentLeagueId(),
            'display_name' => 'Kid',
        ]);

        $response = $this->dispatch($manager);

        $this->assertSame(403, $response->status);
    }
}
