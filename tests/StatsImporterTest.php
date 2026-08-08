<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Scoring\StatsImporter;
use FFB\Tests\Support\DatabaseTestCase;

final class StatsImporterTest extends DatabaseTestCase
{
    private function seasonId(): int
    {
        return (new LeagueRepository($this->pdo))->currentSeasonId();
    }

    private function importer(): StatsImporter
    {
        return new StatsImporter(new PlayerWeekStatsRepository($this->pdo), new PlayerRepository($this->pdo));
    }

    public function testImportsKnownPlayersAndSkipsUnknown(): void
    {
        $seasonId = $this->seasonId();
        (new PlayerRepository($this->pdo))->upsert('KNOWN', null, 'Known', 'QB', 'KC', 'Active', 1);

        $written = $this->importer()->importSleeper($seasonId, 1, [
            'KNOWN' => ['pass_yard' => 250, 'pass_td' => 2],
            'GHOST' => ['pass_yard' => 999], // not in players -> skipped
        ]);

        $this->assertSame(1, $written);
        $resolved = (new PlayerWeekStatsRepository($this->pdo))->resolvedForWeek($seasonId, 1);
        $this->assertArrayHasKey('KNOWN', $resolved);
        $this->assertArrayNotHasKey('GHOST', $resolved);
    }

    public function testImportNflverseMapsGsisToSleeperAndSkipsUnmatched(): void
    {
        $seasonId = $this->seasonId();
        (new PlayerRepository($this->pdo))->upsert('SLEEP1', 'GSIS1', 'Linked', 'RB', 'KC', 'Active', 1);

        $written = $this->importer()->importNflverse($seasonId, 1, [
            'GSIS1' => ['rush_yard' => 100, 'rush_td' => 1],
            'GSIS_UNMATCHED' => ['rush_yard' => 50], // no player carries this link -> skipped
        ]);

        $this->assertSame(1, $written);
        $resolved = (new PlayerWeekStatsRepository($this->pdo))->resolvedForWeek($seasonId, 1);
        $this->assertArrayHasKey('SLEEP1', $resolved);
        $this->assertSame(100.0, $resolved['SLEEP1']['rush_yard']);
    }
}
