<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\PlayerRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Team defenses carry no Sleeper rank, so PlayerRepository::assignDefenseRanks
 * applies the FantasyPros consensus order — verified here to be non-alphabetical,
 * self-consistent, and slotted into the overall board.
 */
final class AssignDefenseRanksTest extends DatabaseTestCase
{
    private function seed(string $id, string $name, string $position, string $team, ?int $rank): void
    {
        (new PlayerRepository($this->pdo))->upsert($id, null, $name, $position, $team, 'Active', $rank);
    }

    private function rankOf(string $id): ?int
    {
        $stmt = $this->pdo->prepare('SELECT search_rank FROM players WHERE sleeper_id = ?');
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }

    public function testDefensesAreRankedByTheSuppliedConsensusOrder(): void
    {
        $this->seed('DST_KC', 'Kansas City Defense', 'DEF', 'KC', null);
        $this->seed('DST_SF', 'San Francisco Defense', 'DEF', 'SF', null);
        $this->seed('DST_ARI', 'Arizona Defense', 'DEF', 'ARI', null);
        // A defense whose team is absent from the ranking sorts last.
        $this->seed('DST_NE', 'New England Defense', 'DEF', 'NE', null);

        // FantasyPros-style order: SF best, then KC, then ARI; NE unranked.
        $count = (new PlayerRepository($this->pdo))->assignDefenseRanks(['SF' => 3, 'KC' => 7, 'ARI' => 30]);
        $this->assertSame(4, $count);

        $this->assertLessThan($this->rankOf('DST_KC'), $this->rankOf('DST_SF'));
        $this->assertLessThan($this->rankOf('DST_ARI'), $this->rankOf('DST_KC'));
        $this->assertLessThan($this->rankOf('DST_NE'), $this->rankOf('DST_ARI'));

        // They land in the overall board (a real number), not left null/last.
        $this->assertNotNull($this->rankOf('DST_SF'));
        $this->assertGreaterThanOrEqual(100, $this->rankOf('DST_SF'));
    }

    public function testFilteredDefensePoolFollowsConsensusNotAlphabetical(): void
    {
        // Alphabetical would put Arizona first; consensus puts KC first.
        $this->seed('DST_ARI', 'Arizona Defense', 'DEF', 'ARI', null);
        $this->seed('DST_KC', 'Kansas City Defense', 'DEF', 'KC', null);
        $players = new PlayerRepository($this->pdo);

        $players->assignDefenseRanks(['KC' => 1, 'ARI' => 32]);
        $ordered = array_column($players->availableForDraft(999, null, 'DEF'), 'sleeper_id');

        $this->assertSame(['DST_KC', 'DST_ARI'], $ordered);
    }
}
