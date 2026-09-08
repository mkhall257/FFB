<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\PlayerRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Team defenses carry no Sleeper rank, so PlayerRepository::assignDefenseRanks
 * derives one from the team's offensive strength — verified here to be
 * non-alphabetical, self-consistent, and slotted into the overall board.
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

    public function testDefensesAreRankedByTeamOffensiveStrength(): void
    {
        // KC has the best skill player (rank 1), then BUF (10), then CHI (200).
        $this->seed('KC_QB', 'KC Quarterback', 'QB', 'KC', 1);
        $this->seed('BUF_RB', 'BUF Runningback', 'RB', 'BUF', 10);
        $this->seed('CHI_WR', 'CHI Receiver', 'WR', 'CHI', 200);
        // A team with no ranked skill players sorts last.
        $this->seed('NE_QB', 'NE Quarterback', 'QB', 'NE', null);

        $this->seed('DST_KC', 'Kansas City Defense', 'DEF', 'KC', null);
        $this->seed('DST_BUF', 'Buffalo Defense', 'DEF', 'BUF', null);
        $this->seed('DST_CHI', 'Chicago Defense', 'DEF', 'CHI', null);
        $this->seed('DST_NE', 'New England Defense', 'DEF', 'NE', null);

        $count = (new PlayerRepository($this->pdo))->assignDefenseRanks();
        $this->assertSame(4, $count);

        // Strongest offense first, unranked team last — and strictly ordered.
        $this->assertLessThan($this->rankOf('DST_BUF'), $this->rankOf('DST_KC'));
        $this->assertLessThan($this->rankOf('DST_CHI'), $this->rankOf('DST_BUF'));
        $this->assertLessThan($this->rankOf('DST_NE'), $this->rankOf('DST_CHI'));

        // They land in the overall board (a real number), not left null/last.
        $this->assertNotNull($this->rankOf('DST_KC'));
        $this->assertGreaterThanOrEqual(100, $this->rankOf('DST_KC'));
    }

    public function testFilteredDefensePoolNoLongerComesBackAlphabetical(): void
    {
        // Alphabetical would put Arizona first; strength ordering puts KC first.
        $this->seed('ARI_QB', 'ARI Quarterback', 'QB', 'ARI', 300);
        $this->seed('KC_QB', 'KC Quarterback', 'QB', 'KC', 1);
        $this->seed('DST_ARI', 'Arizona Defense', 'DEF', 'ARI', null);
        $this->seed('DST_KC', 'Kansas City Defense', 'DEF', 'KC', null);
        $players = new PlayerRepository($this->pdo);

        $players->assignDefenseRanks();
        $ordered = array_column($players->availableForDraft(999, null, 'DEF'), 'sleeper_id');

        $this->assertSame(['DST_KC', 'DST_ARI'], $ordered);
    }
}
