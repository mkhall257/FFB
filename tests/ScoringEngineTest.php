<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Scoring\ScoringEngine;
use PHPUnit\Framework\TestCase;

final class ScoringEngineTest extends TestCase
{
    /** @return array<string,string> */
    private function halfPpr(): array
    {
        return [
            'scoring.reception' => '0.5', 'scoring.pass_yard' => '0.04', 'scoring.pass_td' => '4',
            'scoring.pass_int' => '-2', 'scoring.rush_yard' => '0.1', 'scoring.rush_td' => '6',
            'scoring.rec_yard' => '0.1', 'scoring.rec_td' => '6', 'scoring.fumble_lost' => '-2',
        ];
    }

    /** @return array<string,string> */
    private function defenseSettings(): array
    {
        return [
            'scoring.def_sack' => '1', 'scoring.def_int' => '2', 'scoring.def_fumble_rec' => '2',
            'scoring.def_td' => '6', 'scoring.def_safety' => '2',
            'scoring.def_pa_0' => '10', 'scoring.def_pa_1_6' => '7', 'scoring.def_pa_7_13' => '4',
            'scoring.def_pa_14_20' => '1', 'scoring.def_pa_21_27' => '0',
            'scoring.def_pa_28_34' => '-1', 'scoring.def_pa_35' => '-4',
        ];
    }

    public function testScoresAHalfPprReceivingLine(): void
    {
        // 5 rec, 80 rec yds, 1 rec TD = 2.5 + 8.0 + 6.0 = 16.5
        $points = (new ScoringEngine())->pointsFor(
            ['reception' => 5, 'rec_yard' => 80, 'rec_td' => 1],
            $this->halfPpr(),
        );
        $this->assertSame(16.5, $points);
    }

    public function testScoresAPassingLineWithInterception(): void
    {
        // 300 pass yds (12.0) + 2 pass TD (8.0) - 1 INT (2.0) = 18.0
        $points = (new ScoringEngine())->pointsFor(
            ['pass_yard' => 300, 'pass_td' => 2, 'pass_int' => 1],
            $this->halfPpr(),
        );
        $this->assertSame(18.0, $points);
    }

    public function testUnknownStatsAreIgnored(): void
    {
        $points = (new ScoringEngine())->pointsFor(
            ['reception' => 2, 'made_up_stat' => 999],
            $this->halfPpr(),
        );
        $this->assertSame(1.0, $points);
    }

    public function testKickerScoresFieldGoalsAndExtraPoints(): void
    {
        // 3 FG (9.0) + 2 XP (2.0) = 11.0
        $points = (new ScoringEngine())->pointsFor(
            ['fg_made' => 3, 'xp_made' => 2],
            ['scoring.fg_made' => '3', 'scoring.xp_made' => '1'],
        );
        $this->assertSame(11.0, $points);
    }

    public function testDefenseShutoutScoresEventsPlusTopTier(): void
    {
        // 3 sacks (3.0) + 1 INT (2.0) + 0 pts allowed tier (10.0) = 15.0
        $points = (new ScoringEngine())->pointsFor(
            ['def_sack' => 3, 'def_int' => 1, 'def_points_allowed' => 0],
            $this->defenseSettings(),
        );
        $this->assertSame(15.0, $points);
    }

    public function testDefenseMidTierPointsAllowed(): void
    {
        // 24 pts allowed -> def_pa_21_27 = 0.0; 2 sacks (2.0) = 2.0
        $points = (new ScoringEngine())->pointsFor(
            ['def_sack' => 2, 'def_points_allowed' => 24],
            $this->defenseSettings(),
        );
        $this->assertSame(2.0, $points);
    }

    public function testDefenseBlowoutAllowedIsPenalized(): void
    {
        // 40 pts allowed -> def_pa_35 = -4.0, no events = -4.0
        $points = (new ScoringEngine())->pointsFor(
            ['def_points_allowed' => 40],
            $this->defenseSettings(),
        );
        $this->assertSame(-4.0, $points);
    }
}
