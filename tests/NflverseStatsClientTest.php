<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Scoring\NflverseStatsClient;
use PHPUnit\Framework\TestCase;

final class NflverseStatsClientTest extends TestCase
{
    private const HEADER = 'player_id,week,receptions,passing_yards,passing_tds,interceptions,'
        . 'sack_fumbles_lost,rushing_yards,rushing_tds,rushing_fumbles_lost,'
        . 'receiving_yards,receiving_tds,receiving_fumbles_lost';

    public function testSumsFumblesLostAcrossSackRushingAndReceiving(): void
    {
        $csv = self::HEADER . "\n"
            // QB: 300 pass yds, 2 TD, 1 sack fumble lost, 1 rushing fumble lost
            . "00-0000001,1,0,300,2,0,1,10,0,1,0,0,0\n";

        $lines = (new NflverseStatsClient())->rowsForWeek($csv, 1);

        $this->assertArrayHasKey('00-0000001', $lines);
        $this->assertSame(2.0, $lines['00-0000001']['fumble_lost'], 'sack + rushing fumbles summed');
        $this->assertSame(300.0, $lines['00-0000001']['pass_yard']);
    }

    public function testKeepsOnlyTheRequestedWeek(): void
    {
        $csv = self::HEADER . "\n"
            . "00-0000001,1,5,0,0,0,0,0,0,0,60,1,0\n"
            . "00-0000002,2,9,0,0,0,0,0,0,0,90,0,0\n";

        $lines = (new NflverseStatsClient())->rowsForWeek($csv, 1);

        $this->assertArrayHasKey('00-0000001', $lines);
        $this->assertArrayNotHasKey('00-0000002', $lines);
        $this->assertSame(5.0, $lines['00-0000001']['reception']);
    }

    public function testOmitsZeroFumbles(): void
    {
        $csv = self::HEADER . "\n"
            . "00-0000001,1,0,250,1,0,0,0,0,0,0,0,0\n";

        $lines = (new NflverseStatsClient())->rowsForWeek($csv, 1);
        $this->assertArrayNotHasKey('fumble_lost', $lines['00-0000001']);
    }
}
