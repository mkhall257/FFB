<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * The free-agent pool (Add/Drop) filtering: search by player name or NFL team,
 * and the unlimited-by-default result set.
 */
final class PlayerAvailableForSeasonTest extends DatabaseTestCase
{
    private function seasonId(): int
    {
        return (new LeagueRepository($this->pdo))->currentSeasonId();
    }

    private function seed(string $id, string $name, string $position, string $team, ?int $rank): void
    {
        (new PlayerRepository($this->pdo))->upsert($id, null, $name, $position, $team, 'Active', $rank);
    }

    public function testSearchFindsDefenseByTeamOrName(): void
    {
        $this->seed('DST_SF', 'San Francisco Defense', 'DEF', 'SF', null);
        $this->seed('QB_KC', 'Patrick Mahomes', 'QB', 'KC', 1);
        $players = new PlayerRepository($this->pdo);
        $seasonId = $this->seasonId();

        $byTeam = $players->availableForSeason($seasonId, 'SF', null);
        $this->assertSame(['DST_SF'], array_column($byTeam, 'sleeper_id'));

        $byName = $players->availableForSeason($seasonId, 'francisco', null);
        $this->assertSame(['DST_SF'], array_column($byName, 'sleeper_id'));

        $byPlayer = $players->availableForSeason($seasonId, 'mahom', null);
        $this->assertSame(['QB_KC'], array_column($byPlayer, 'sleeper_id'));
    }

    public function testOmittingTheLimitReturnsTheWholeFreeAgentPool(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seed("P{$i}", "Player {$i}", 'WR', 'KC', $i);
        }
        $players = new PlayerRepository($this->pdo);
        $seasonId = $this->seasonId();

        $this->assertCount(5, $players->availableForSeason($seasonId));
        $this->assertCount(2, $players->availableForSeason($seasonId, null, null, 2));
    }
}
