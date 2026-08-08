<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\PlayoffRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Slice 5 — a tied playoff game is decided by the highest-scoring single starter,
 * then the next-highest, so a lower seed can knock out a higher seed on the field.
 * (The higher-seed backstop, used only when starter vectors are identical, is
 * covered in PlayoffAdvanceHttpTest.)
 */
final class PlayoffTiebreakHttpTest extends DatabaseTestCase
{
    private const PLAYOFF_WEEK_1 = 15;

    private int $leagueId;
    private int $seasonId;

    protected function setUp(): void
    {
        parent::setUp();
        $leagues = new LeagueRepository($this->pdo);
        $this->leagueId = $leagues->currentLeagueId();
        $this->seasonId = $leagues->currentSeasonId();
    }

    private function commissioner(): ArraySession
    {
        return new ArraySession([
            'user_id' => 9999, 'role' => 'commissioner',
            'league_id' => $this->leagueId, 'display_name' => 'Boss',
        ]);
    }

    private function dispatch(string $method, string $path, array $post = []): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $this->commissioner());
    }

    /** A 4-team field, seeded and with Round 1 opened. */
    private function fourTeamBracket(): void
    {
        $teams = new TeamRepository($this->pdo);
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $teams->create($this->leagueId, $this->seasonId, 'Seed ' . ($i + 1));
        }
        $score = 200;
        for ($i = 0; $i < 4; $i += 2) {
            $this->pdo->prepare(
                'INSERT INTO matchups (league_id, season_id, week, round, home_team_id, away_team_id, home_score, away_score, status)'
                . " VALUES (?,?,14,NULL,?,?,?,?,'final')"
            )->execute([$this->leagueId, $this->seasonId, $ids[$i], $ids[$i + 1], $score, $score - 1]);
            $score -= 10;
        }
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'playoffs.team_count' => '4',
        ]);
        $this->dispatch('POST', '/admin/playoffs/create');
    }

    private function seedTeam(int $seed): int
    {
        return (new PlayoffRepository($this->pdo))->seeds($this->seasonId)[$seed];
    }

    /** @return list<array<string,mixed>> */
    private function round(int $round): array
    {
        return (new MatchupRepository($this->pdo))->forRound($this->seasonId, $round);
    }

    private function settle(int $matchupId, float $hs, float $as): void
    {
        $this->pdo->prepare(
            "UPDATE matchups SET home_score = ?, away_score = ?, status = 'final' WHERE id = ?"
        )->execute([$hs, $as, $matchupId]);
    }

    /**
     * Start two players for a team in the playoff week and give them stat lines
     * that score to $high and $low points (pass_yard is 0.04/yd by default).
     */
    private function startTwo(int $teamId, string $prefix, float $high, float $low): void
    {
        $players = new PlayerRepository($this->pdo);
        $stats = new PlayerWeekStatsRepository($this->pdo);
        $a = $prefix . 'A';
        $b = $prefix . 'B';
        $players->upsert($a, null, $a, 'QB', 'KC', 'Active', 1);
        $players->upsert($b, null, $b, 'WR', 'KC', 'Active', 1);
        (new LineupRepository($this->pdo))->replaceForTeamWeek(
            $this->leagueId, $this->seasonId, self::PLAYOFF_WEEK_1, $teamId, [
                ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => $a],
                ['roster_slot' => 'WR', 'slot_index' => 0, 'player_id' => $b],
            ],
        );
        // pass_yard weight is 0.04 → yards = points / 0.04 = points * 25.
        $stats->upsert($this->seasonId, self::PLAYOFF_WEEK_1, $a, 'sleeper', ['pass_yard' => $high * 25]);
        $stats->upsert($this->seasonId, self::PLAYOFF_WEEK_1, $b, 'sleeper', ['pass_yard' => $low * 25]);
    }

    /** Settle the s2 v s3 game (Round-1 game index 1) so the tie game is the focus. */
    private function settleOtherGameSoSeedTwoWins(): void
    {
        $game = $this->round(1)[1];
        $home = (int) $game['home_team_id'];
        [$hs, $as] = $home === $this->seedTeam(2) ? [100.0, 90.0] : [90.0, 100.0];
        $this->settle((int) $game['id'], $hs, $as);
    }

    public function testTopStarterBreaksTheTieForTheLowerSeed(): void
    {
        $this->fourTeamBracket();

        // Game 0 is seed1 (home) vs seed4. Equal totals (60 each), but seed4's
        // top starter (50) beats seed1's top starter (40): seed4 advances.
        $this->startTwo($this->seedTeam(1), 'S1', 40, 20);
        $this->startTwo($this->seedTeam(4), 'S4', 50, 10);
        $this->settle((int) $this->round(1)[0]['id'], 60, 60);
        $this->settleOtherGameSoSeedTwoWins();

        $this->dispatch('POST', '/admin/playoffs/advance');

        $final = $this->round(2);
        $this->assertCount(1, $final);
        // seed4 upset seed1 on the tiebreak, so the final is seed4 vs seed2.
        $this->assertSame($this->seedTeam(4), (int) $final[0]['home_team_id']);
        $this->assertSame($this->seedTeam(2), (int) $final[0]['away_team_id']);
    }

    public function testSecondStarterBreaksTheTieWhenTopStartersMatch(): void
    {
        $this->fourTeamBracket();

        // Both top starters are 50; seed1's second is 5, seed4's is 20 → seed4 wins.
        $this->startTwo($this->seedTeam(1), 'S1', 50, 5);
        $this->startTwo($this->seedTeam(4), 'S4', 50, 20);
        $this->settle((int) $this->round(1)[0]['id'], 55, 55);
        $this->settleOtherGameSoSeedTwoWins();

        $this->dispatch('POST', '/admin/playoffs/advance');

        $final = $this->round(2);
        $this->assertSame($this->seedTeam(4), (int) $final[0]['home_team_id']);
    }
}
