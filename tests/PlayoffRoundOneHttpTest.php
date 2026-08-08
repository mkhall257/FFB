<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\MatchupRepository;
use FFB\PlayoffRepository;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Slice 3 — creating the bracket also opens Round 1: the standard slotting is
 * turned into Matchup rows (round = 1) at week regular_season_weeks + 1, with
 * top seeds getting byes when the field isn't a power of two.
 */
final class PlayoffRoundOneHttpTest extends DatabaseTestCase
{
    private const REGULAR_WEEKS = 14;
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

    /**
     * Build $count teams and settle the final regular week so their seed order
     * is exactly team #1 = seed 1, team #2 = seed 2, … (each team beats the next).
     *
     * @return list<int> team ids indexed 0 = seed 1
     */
    private function seededLeague(int $count): array
    {
        $teams = new TeamRepository($this->pdo);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $teams->create($this->leagueId, $this->seasonId, 'Seed ' . ($i + 1));
        }

        // Give each team a points-for that strictly orders them (seed 1 highest).
        // One final-week matchup per team pair isn't enough to order all of them,
        // so use non-overlapping single-team "games" against a blowout to set PF.
        // Simpler: pair them and pick scores so standings = id order via points-for
        // with everyone at the same record. Each team plays one final-week game.
        $score = 200;
        for ($i = 0; $i < $count - 1; $i += 2) {
            // team i (higher) beats team i+1, and higher i has higher score.
            $this->finalMatchup($ids[$i], $ids[$i + 1], $score, $score - 1);
            $score -= 10;
        }
        if ($count % 2 === 1) {
            // Odd team out: give the last team a low-scoring win so it seeds last.
            $this->finalMatchup($ids[$count - 1], $ids[0], 5, 4);
        }

        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'playoffs.team_count' => (string) $count,
        ]);

        return $ids;
    }

    private function finalMatchup(int $home, int $away, float $hs, float $as): void
    {
        $this->pdo->prepare(
            'INSERT INTO matchups (league_id, season_id, week, round, home_team_id, away_team_id, home_score, away_score, status)'
            . " VALUES (?,?,?,NULL,?,?,?,?,'final')"
        )->execute([$this->leagueId, $this->seasonId, self::REGULAR_WEEKS, $home, $away, $hs, $as]);
    }

    /** @return list<array{home:int,away:int}> round-1 pairings by team id */
    private function roundOnePairings(): array
    {
        $out = [];
        foreach ((new MatchupRepository($this->pdo))->forRound($this->seasonId, 1) as $m) {
            $this->assertSame(self::PLAYOFF_WEEK_1, (int) $m['week']);
            $out[] = ['home' => (int) $m['home_team_id'], 'away' => (int) $m['away_team_id']];
        }

        return $out;
    }

    private function seedTeam(int $seed): int
    {
        return (new PlayoffRepository($this->pdo))->seeds($this->seasonId)[$seed];
    }

    private function currentWeek(): string
    {
        return (new LeagueSettingsRepository($this->pdo))
            ->all($this->leagueId, $this->seasonId)['schedule.current_week'] ?? '';
    }

    public function testFourTeamFieldPairsOneVsFourAndTwoVsThree(): void
    {
        $this->seededLeague(4);
        $this->dispatch('POST', '/admin/playoffs/create');

        $this->assertSame([
            ['home' => $this->seedTeam(1), 'away' => $this->seedTeam(4)],
            ['home' => $this->seedTeam(2), 'away' => $this->seedTeam(3)],
        ], $this->roundOnePairings());
        $this->assertSame((string) self::PLAYOFF_WEEK_1, $this->currentWeek());
    }

    public function testSixTeamFieldGivesTopTwoByesAndPlaysFourGamesWorth(): void
    {
        $this->seededLeague(6);
        $this->dispatch('POST', '/admin/playoffs/create');

        // Byes for seeds 1 and 2: they have no Round-1 matchup. Games: 4v5, 3v6.
        $pairings = $this->roundOnePairings();
        $this->assertCount(2, $pairings);
        $this->assertSame([
            ['home' => $this->seedTeam(4), 'away' => $this->seedTeam(5)],
            ['home' => $this->seedTeam(3), 'away' => $this->seedTeam(6)],
        ], $pairings);

        // Neither top seed appears in Round 1.
        $teamsInRound1 = [];
        foreach ($pairings as $p) {
            $teamsInRound1[] = $p['home'];
            $teamsInRound1[] = $p['away'];
        }
        $this->assertNotContains($this->seedTeam(1), $teamsInRound1);
        $this->assertNotContains($this->seedTeam(2), $teamsInRound1);
    }

    public function testEightTeamFieldPlaysFourFirstRoundGames(): void
    {
        $this->seededLeague(8);
        $this->dispatch('POST', '/admin/playoffs/create');
        $this->assertCount(4, $this->roundOnePairings());
    }

    public function testTwoTeamFieldIsASingleGame(): void
    {
        $this->seededLeague(2);
        $this->dispatch('POST', '/admin/playoffs/create');

        $this->assertSame([
            ['home' => $this->seedTeam(1), 'away' => $this->seedTeam(2)],
        ], $this->roundOnePairings());
    }
}
