<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Players\NflByeWeeks;
use FFB\PlayerRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * The NFL bye-week derivation (nflverse schedule => team => bye week) and its
 * application to the Player universe.
 */
final class NflByeWeeksTest extends DatabaseTestCase
{
    /**
     * A tiny schedule the parser can reason about: max week 4, so a team missing
     * exactly one of weeks 1–4 has that week as its bye.
     *   KC  plays 1,2,4   -> bye 3
     *   LA  plays 1,3,4   -> bye 2  (normalized to Sleeper's LAR)
     *   SF  plays 1,2,3,4 -> no bye (excluded)
     *   BUF plays 1 only  -> two-plus missing (excluded, not guessed)
     */
    private const FIXTURE = <<<CSV
        season,game_type,week,home_team,away_team
        2026,REG,1,SF,KC
        2026,REG,1,BUF,LA
        2026,REG,2,KC,SF
        2026,REG,2,NE,DEN
        2026,REG,3,SF,LA
        2026,REG,4,LA,KC
        2026,REG,4,DEN,SF
        2026,POST,1,KC,SF
        2025,REG,1,KC,SF
        CSV;

    private function fixtureCsv(): string
    {
        // Strip the leading indentation the heredoc carries in this test.
        return implode("\n", array_map('trim', explode("\n", self::FIXTURE)));
    }

    public function testParseDerivesEachTeamsSingleMissingWeek(): void
    {
        $byes = NflByeWeeks::parse($this->fixtureCsv(), 2026);

        $this->assertSame(3, $byes['KC']);
        $this->assertSame(2, $byes['LAR'], 'nflverse LA is normalized to Sleeper LAR');
    }

    public function testParseExcludesTeamsWithoutExactlyOneBye(): void
    {
        $byes = NflByeWeeks::parse($this->fixtureCsv(), 2026);

        $this->assertArrayNotHasKey('SF', $byes, 'a team that plays every week has no bye');
        $this->assertArrayNotHasKey('BUF', $byes, 'an incomplete schedule is not guessed');
    }

    public function testParseIgnoresOtherSeasonsAndNonRegularGames(): void
    {
        // The 2025 row and the POST game must not affect the 2026 derivation.
        $byes = NflByeWeeks::parse($this->fixtureCsv(), 2026);
        $this->assertSame(3, $byes['KC']);

        // A season absent from the feed yields nothing.
        $this->assertSame([], NflByeWeeks::parse($this->fixtureCsv(), 2099));
    }

    public function testAssignByeWeeksWritesTheMapAndClearsStale(): void
    {
        $players = new PlayerRepository($this->pdo);
        $players->upsert('KC_QB', null, 'Kansas Passer', 'QB', 'KC', 'Active', 1);
        $players->upsert('LAR_DEF', null, 'Rams Defense', 'DEF', 'LAR', 'Active', 150);
        $players->upsert('SF_WR', null, 'Niner Catcher', 'WR', 'SF', 'Active', 2);

        // A stale bye that a later sync should clear.
        $this->pdo->exec("UPDATE players SET bye_week = 9 WHERE sleeper_id = 'SF_WR'");

        $updated = $players->assignByeWeeks(['KC' => 3, 'LAR' => 2]);

        $this->assertGreaterThanOrEqual(2, $updated);
        $this->assertSame(3, $this->byeOf('KC_QB'));
        $this->assertSame(2, $this->byeOf('LAR_DEF'));
        $this->assertNull($this->byeOf('SF_WR'), 'a team no longer in the map is cleared, not left stale');
    }

    private function byeOf(string $sleeperId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT bye_week FROM players WHERE sleeper_id = ?');
        $stmt->execute([$sleeperId]);
        $value = $stmt->fetchColumn();

        return $value === null || $value === false ? null : (int) $value;
    }
}
