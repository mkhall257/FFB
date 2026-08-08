<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Players\PlayerIdCrosswalk;
use FFB\Players\PlayerImporter;
use FFB\PlayerRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Exercises the player import against fixtures (no network): the Sleeper feed
 * and the id crosswalk are both loaded from files.
 */
final class PlayerImporterTest extends DatabaseTestCase
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

    private function importFixture(): \FFB\Players\ImportResult
    {
        return (new PlayerImporter(new PlayerRepository($this->pdo)))
            ->import($this->sleeperFixture(), $this->crosswalkFixture());
    }

    private function playerRow(string $sleeperId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM players WHERE sleeper_id = ?');
        $stmt->execute([$sleeperId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function testCrosswalkParsesSleeperToGsisPairs(): void
    {
        $map = $this->crosswalkFixture();

        $this->assertSame('00-0033873', $map['4046']);
        $this->assertSame('00-0036000', $map['6790']);
        $this->assertArrayNotHasKey('9999', $map);
    }

    public function testOnlyRosterablePlayersAreImported(): void
    {
        $result = $this->importFixture();

        // Imported: QB(KC), WR(DAL), RB(TB), QB(free agent), DEF(KC), WR(SF) = 6.
        // Skipped: inactive WR, offensive lineman (OT).
        $this->assertSame(6, $result->upserted);
        $this->assertSame(6, (new PlayerRepository($this->pdo))->count());
        $this->assertNull($this->playerRow('1234'), 'inactive player should not be imported');
        $this->assertNull($this->playerRow('7777'), 'non-rosterable position should not be imported');
    }

    public function testNflverseIdIsLinkedFromCrosswalk(): void
    {
        $this->importFixture();

        $this->assertSame('00-0033873', $this->playerRow('4046')['nflverse_id']);
        $this->assertSame('00-0036000', $this->playerRow('6790')['nflverse_id']);
    }

    public function testSleeperGsisIsUsedAsFallbackAndTrimmed(): void
    {
        $this->importFixture();

        // 8888 is not in the crosswalk but has a Sleeper gsis_id with a leading space.
        $this->assertSame('00-0099999', $this->playerRow('8888')['nflverse_id']);
    }

    public function testSleeperSearchRankIsStored(): void
    {
        $this->importFixture();

        $this->assertSame(5, (int) $this->playerRow('4046')['search_rank']);
        $this->assertSame(12, (int) $this->playerRow('6790')['search_rank']);
    }

    public function testMissingSearchRankIsStoredAsNull(): void
    {
        $this->importFixture();

        // The KC defense fixture carries no search_rank.
        $this->assertNull($this->playerRow('KC')['search_rank']);
    }

    public function testTeamDefenseIsImportedWithFallbackName(): void
    {
        $this->importFixture();

        $def = $this->playerRow('KC');
        $this->assertNotNull($def);
        $this->assertSame('DEF', $def['position']);
        $this->assertSame('KC Defense', $def['full_name']);
    }

    public function testUnmatchedIsTheActiveTeamedSkillPlayerWithNoLink(): void
    {
        $result = $this->importFixture();

        // Only the undrafted RB (9999): active, on a team, skill position, no link.
        $this->assertSame(1, $result->unmatchedCount());
        $this->assertSame('9999', $result->unmatched[0]['sleeper_id']);

        // The DB review query agrees.
        $unmatched = (new PlayerRepository($this->pdo))->listUnmatched();
        $this->assertCount(1, $unmatched);
        $this->assertSame('Undrafted Rookie', $unmatched[0]['full_name']);
    }

    public function testFreeAgentWithoutTeamIsNotCountedUnmatched(): void
    {
        $result = $this->importFixture();

        // 5555 has no nflverse link but also no team, so it is not a scoring gap.
        $sleeperIds = array_column($result->unmatched, 'sleeper_id');
        $this->assertNotContains('5555', $sleeperIds);
        $this->assertNotNull($this->playerRow('5555'), 'the free agent is still catalogued');
    }

    public function testReimportUpdatesRatherThanDuplicates(): void
    {
        $this->importFixture();

        $players = $this->sleeperFixture();
        $players['9999']['team'] = 'CIN';
        (new PlayerImporter(new PlayerRepository($this->pdo)))->import($players, $this->crosswalkFixture());

        $this->assertSame(6, (new PlayerRepository($this->pdo))->count(), 're-import must not duplicate');
        $this->assertSame('CIN', $this->playerRow('9999')['nfl_team'], 're-import must update fields');
    }
}
