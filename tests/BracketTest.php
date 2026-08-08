<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Playoffs\Bracket;
use PHPUnit\Framework\TestCase;

/**
 * The pure bracket math: standard placement, byes for non-powers-of-two, and
 * round counts. No database — this is combinatorial logic (cf. ScheduleGeneratorTest).
 */
final class BracketTest extends TestCase
{
    public function testNextPowerOfTwo(): void
    {
        $this->assertSame(2, Bracket::nextPowerOfTwo(2));
        $this->assertSame(4, Bracket::nextPowerOfTwo(3));
        $this->assertSame(4, Bracket::nextPowerOfTwo(4));
        $this->assertSame(8, Bracket::nextPowerOfTwo(5));
        $this->assertSame(8, Bracket::nextPowerOfTwo(8));
    }

    public function testStandardSlotsForEight(): void
    {
        // The canonical 8-seed bracket order.
        $this->assertSame([1, 8, 4, 5, 2, 7, 3, 6], Bracket::seedSlots(8));
    }

    public function testStandardSlotsForFour(): void
    {
        $this->assertSame([1, 4, 2, 3], Bracket::seedSlots(4));
    }

    public function testPowerOfTwoFieldHasNoByes(): void
    {
        $this->assertSame([], Bracket::firstRoundByes(8));
        $this->assertSame([], Bracket::firstRoundByes(4));
        $this->assertSame([], Bracket::firstRoundByes(2));
    }

    public function testEightTeamFirstRoundGames(): void
    {
        // 1v8, 4v5, 2v7, 3v6 — higher seed is home.
        $this->assertSame([
            ['high' => 1, 'low' => 8],
            ['high' => 4, 'low' => 5],
            ['high' => 2, 'low' => 7],
            ['high' => 3, 'low' => 6],
        ], Bracket::firstRoundGames(8));
    }

    public function testSixTeamFieldByesTopTwoSeeds(): void
    {
        $this->assertSame([1, 2], Bracket::firstRoundByes(6));
        // Seeds 3–6 play in: 4v5 and 3v6.
        $this->assertSame([
            ['high' => 4, 'low' => 5],
            ['high' => 3, 'low' => 6],
        ], Bracket::firstRoundGames(6));
    }

    public function testFiveTeamFieldByesTopThreeSeeds(): void
    {
        $this->assertSame([1, 2, 3], Bracket::firstRoundByes(5));
        $this->assertSame([['high' => 4, 'low' => 5]], Bracket::firstRoundGames(5));
    }

    public function testThreeTeamFieldByesTopSeed(): void
    {
        $this->assertSame([1], Bracket::firstRoundByes(3));
        $this->assertSame([['high' => 2, 'low' => 3]], Bracket::firstRoundGames(3));
    }

    public function testSevenTeamFieldByesTopSeedOnly(): void
    {
        $this->assertSame([1], Bracket::firstRoundByes(7));
        $this->assertSame([
            ['high' => 4, 'low' => 5],
            ['high' => 2, 'low' => 7],
            ['high' => 3, 'low' => 6],
        ], Bracket::firstRoundGames(7));
    }

    public function testTwoTeamFieldIsASingleGame(): void
    {
        $this->assertSame([['high' => 1, 'low' => 2]], Bracket::firstRoundGames(2));
        $this->assertSame(1, Bracket::roundCount(2));
    }

    public function testRoundCounts(): void
    {
        $this->assertSame(1, Bracket::roundCount(2));
        $this->assertSame(2, Bracket::roundCount(3));
        $this->assertSame(2, Bracket::roundCount(4));
        $this->assertSame(3, Bracket::roundCount(5));
        $this->assertSame(3, Bracket::roundCount(8));
    }
}
