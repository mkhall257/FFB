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
 * Slice 4 — advancing the bracket pairs the survivors into the next tree slots
 * (byes interleaved for Round 1→2), opens that week with a kickoff, and refuses
 * to advance an unfinished or already-decided bracket.
 */
final class PlayoffAdvanceHttpTest extends DatabaseTestCase
{
    private const REGULAR_WEEKS = 14;

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

    /** Build $count teams, settle the final regular week, set the field size. */
    private function seededLeague(int $count): void
    {
        $teams = new TeamRepository($this->pdo);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $teams->create($this->leagueId, $this->seasonId, 'Seed ' . ($i + 1));
        }
        $score = 200;
        for ($i = 0; $i < $count - 1; $i += 2) {
            $this->pdo->prepare(
                'INSERT INTO matchups (league_id, season_id, week, round, home_team_id, away_team_id, home_score, away_score, status)'
                . " VALUES (?,?,?,NULL,?,?,?,?,'final')"
            )->execute([$this->leagueId, $this->seasonId, self::REGULAR_WEEKS, $ids[$i], $ids[$i + 1], $score, $score - 1]);
            $score -= 10;
        }
        (new LeagueSettingsRepository($this->pdo))->setMany($this->leagueId, $this->seasonId, [
            'playoffs.team_count' => (string) $count,
        ]);
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

    /** Settle every game of a round so that $winnerSeeds win theirs, in order. */
    private function settleRound(int $round, int ...$winnerSeeds): void
    {
        $seedOf = array_flip((new PlayoffRepository($this->pdo))->seeds($this->seasonId));
        $games = $this->round($round);
        foreach ($games as $i => $m) {
            $winnerTeam = $this->seedTeam($winnerSeeds[$i]);
            $home = (int) $m['home_team_id'];
            [$hs, $as] = $winnerTeam === $home ? [100.0, 90.0] : [90.0, 100.0];
            $this->settle((int) $m['id'], $hs, $as);
        }
    }

    private function settle(int $matchupId, float $hs, float $as): void
    {
        $this->pdo->prepare(
            "UPDATE matchups SET home_score = ?, away_score = ?, status = 'final' WHERE id = ?"
        )->execute([$hs, $as, $matchupId]);
    }

    private function currentWeek(): string
    {
        return (new LeagueSettingsRepository($this->pdo))
            ->all($this->leagueId, $this->seasonId)['schedule.current_week'] ?? '';
    }

    private function kickoff(int $week): ?string
    {
        return (new LeagueSettingsRepository($this->pdo))
            ->all($this->leagueId, $this->seasonId)['schedule.week_' . $week . '_kickoff'] ?? null;
    }

    public function testFourTeamFieldAdvancesToASingleFinal(): void
    {
        $this->seededLeague(4);
        $this->dispatch('POST', '/admin/playoffs/create');
        // Round 1: (s1 v s4), (s2 v s3). Top seeds win.
        $this->settleRound(1, 1, 2);

        $response = $this->dispatch('POST', '/admin/playoffs/advance', ['kickoff' => '2026-12-25T20:20']);
        $this->assertSame(302, $response->status);

        $final = $this->round(2);
        $this->assertCount(1, $final);
        $this->assertSame($this->seedTeam(1), (int) $final[0]['home_team_id']);
        $this->assertSame($this->seedTeam(2), (int) $final[0]['away_team_id']);
        $this->assertSame(16, (int) $final[0]['week']);
        $this->assertSame('16', $this->currentWeek());
        $this->assertNotNull($this->kickoff(16));
    }

    public function testSixTeamFieldInterleavesByesIntoTheSemifinals(): void
    {
        $this->seededLeague(6);
        $this->dispatch('POST', '/admin/playoffs/create');
        // Round 1 games: (s4 v s5), (s3 v s6). Higher seeds win.
        $this->settleRound(1, 4, 3);

        $this->dispatch('POST', '/admin/playoffs/advance', ['kickoff' => '2026-12-25T20:20']);

        // Semis: bye s1 v winner(4/5)=s4, bye s2 v winner(3/6)=s3.
        $semis = $this->round(2);
        $this->assertCount(2, $semis);
        $this->assertSame($this->seedTeam(1), (int) $semis[0]['home_team_id']);
        $this->assertSame($this->seedTeam(4), (int) $semis[0]['away_team_id']);
        $this->assertSame($this->seedTeam(2), (int) $semis[1]['home_team_id']);
        $this->assertSame($this->seedTeam(3), (int) $semis[1]['away_team_id']);
    }

    public function testAdvanceRefusedWhileRoundUnfinished(): void
    {
        $this->seededLeague(4);
        $this->dispatch('POST', '/admin/playoffs/create');
        // Settle only one of the two round-1 games.
        $games = $this->round(1);
        $this->settle((int) $games[0]['id'], 100, 90);

        $response = $this->dispatch('POST', '/admin/playoffs/advance');
        $this->assertSame(409, $response->status);
        $this->assertSame([], $this->round(2));
    }

    public function testAdvanceRefusedOnceDecided(): void
    {
        $this->seededLeague(2);
        $this->dispatch('POST', '/admin/playoffs/create');
        $this->settleRound(1, 1); // the final (single game)

        $response = $this->dispatch('POST', '/admin/playoffs/advance');
        $this->assertSame(409, $response->status);
    }

    public function testAdvanceRefusedWithoutABracket(): void
    {
        $response = $this->dispatch('POST', '/admin/playoffs/advance');
        $this->assertSame(409, $response->status);
    }

    public function testTieInAPlayoffGameAdvancesTheHigherSeed(): void
    {
        $this->seededLeague(4);
        $this->dispatch('POST', '/admin/playoffs/create');
        // Round 1: tie both games -> higher seed (s1, s2) advance via the backstop.
        foreach ($this->round(1) as $m) {
            $this->settle((int) $m['id'], 100, 100);
        }

        $this->dispatch('POST', '/admin/playoffs/advance');

        $final = $this->round(2);
        $this->assertCount(1, $final);
        $this->assertSame($this->seedTeam(1), (int) $final[0]['home_team_id']);
        $this->assertSame($this->seedTeam(2), (int) $final[0]['away_team_id']);
    }
}
