<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class PlayerWeekStatsSchemaTest extends DatabaseTestCase
{
    public function testTwoSourcesCoexistForSamePlayerWeek(): void
    {
        $seasonId = (new LeagueRepository($this->pdo))->currentSeasonId();
        (new PlayerRepository($this->pdo))->upsert('P1', null, 'P One', 'QB', 'KC', 'Active', 1);

        $ins = $this->pdo->prepare(
            'INSERT INTO player_week_stats (season_id, week, player_id, source, stats)'
            . ' VALUES (?, 1, ?, ?, ?)'
        );
        $ins->execute([$seasonId, 'P1', 'sleeper', '{"pass_yard":250}']);
        $ins->execute([$seasonId, 'P1', 'nflverse', '{"pass_yard":248}']);

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM player_week_stats WHERE player_id = 'P1'"
        )->fetchColumn();

        $this->assertSame(2, $count);
    }
}
