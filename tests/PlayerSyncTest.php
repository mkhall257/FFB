<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Players\PlayerIdCrosswalk;
use FFB\Players\PlayerSync;
use FFB\PlayerRepository;
use FFB\PlayerSyncLogRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Guards the whole player-sync pipeline ({@see PlayerSync}) against fixtures: an
 * import must always be followed by the defense ranking and byes. The cron once
 * imported without ranking, which left team defenses unranked (blank, then
 * alphabetical) — these tests fail if that pipeline ever loses a step again.
 */
final class PlayerSyncTest extends DatabaseTestCase
{
    /**
     * @return array<string,array<string,mixed>>
     */
    private function sleeperFixture(): array
    {
        /** @var array<string,array<string,mixed>> $data */
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/sleeper_players.json'), true);

        return $data;
    }

    /**
     * @return array<string,string>
     */
    private function crosswalkFixture(): array
    {
        return PlayerIdCrosswalk::parse((string) file_get_contents(__DIR__ . '/fixtures/crosswalk.csv'));
    }

    private function sync(): PlayerSync
    {
        return new PlayerSync(new PlayerRepository($this->pdo), new PlayerSyncLogRepository($this->pdo));
    }

    private function defenseRank(string $sleeperId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT search_rank FROM players WHERE sleeper_id = ?');
        $stmt->execute([$sleeperId]);
        $rank = $stmt->fetchColumn();

        return $rank === false || $rank === null ? null : (int) $rank;
    }

    public function testApplyImportsRanksDefensesAndSetsByes(): void
    {
        $result = $this->sync()->apply(
            $this->sleeperFixture(),
            $this->crosswalkFixture(),
            ['KC' => 1],
            ['KC' => 10],
        );

        // The fixture has one team defense (KC); it is ranked from the base slot.
        $this->assertSame(1, $result->defensesRanked);
        $this->assertSame(140, $this->defenseRank('KC'), 'the KC defense must be ranked, not left null');

        // Byes are applied to every player on the team.
        $stmt = $this->pdo->query("SELECT bye_week FROM players WHERE nfl_team = 'KC' LIMIT 1");
        $this->assertSame(10, (int) $stmt->fetchColumn());
        $this->assertSame(1, $result->byesSet >= 1 ? 1 : 0);
    }

    public function testDefensesAreRankedEvenWhenTheDstFeedIsEmpty(): void
    {
        // This is the exact regression: the import leaves defenses with a null
        // Sleeper rank, so the pipeline must still assign them a rank (falling
        // back to alphabetical order among themselves) rather than leaving them
        // blank and dead-last.
        $result = $this->sync()->apply($this->sleeperFixture(), $this->crosswalkFixture(), [], []);

        $this->assertSame(1, $result->defensesRanked);
        $this->assertSame(140, $this->defenseRank('KC'), 'defense must be ranked even with no DST feed');
    }
}
