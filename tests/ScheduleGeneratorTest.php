<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Schedule\ScheduleGenerator;
use PHPUnit\Framework\TestCase;

final class ScheduleGeneratorTest extends TestCase
{
    public function testEvenTeamsProduceFullWeeksWithNoByes(): void
    {
        $rows = (new ScheduleGenerator())->generate([1, 2, 3, 4], 3);

        // 4 teams -> 2 matchups per week, no byes.
        $byWeek = [];
        foreach ($rows as $r) {
            $byWeek[$r['week']][] = $r;
        }
        $this->assertCount(2, $byWeek[1]);
        $this->assertCount(2, $byWeek[2]);
        $this->assertCount(2, $byWeek[3]);
    }

    public function testEveryTeamPlaysAtMostOncePerWeek(): void
    {
        $rows = (new ScheduleGenerator())->generate([1, 2, 3, 4, 5], 14); // odd -> byes

        $byWeek = [];
        foreach ($rows as $r) {
            $byWeek[$r['week']][] = $r;
        }
        foreach ($byWeek as $week => $matchups) {
            $seen = [];
            foreach ($matchups as $m) {
                $this->assertArrayNotHasKey($m['home_team_id'], $seen, "team double-booked in week $week");
                $this->assertArrayNotHasKey($m['away_team_id'], $seen, "team double-booked in week $week");
                $seen[$m['home_team_id']] = true;
                $seen[$m['away_team_id']] = true;
            }
        }
    }

    public function testFillsExactlyTheRequestedWeeks(): void
    {
        $rows = (new ScheduleGenerator())->generate([1, 2, 3, 4], 14);
        $weeks = array_unique(array_column($rows, 'week'));
        sort($weeks);
        $this->assertSame(range(1, 14), $weeks);
    }

    public function testOddTeamGivesOneByePerWeek(): void
    {
        $rows = (new ScheduleGenerator())->generate([1, 2, 3, 4, 5], 5);
        // 5 teams -> 2 matchups/week (one team byes), so 4 teams play each week.
        $byWeek = [];
        foreach ($rows as $r) {
            $byWeek[$r['week']][] = $r;
        }
        $this->assertCount(2, $byWeek[1]);
    }
}
