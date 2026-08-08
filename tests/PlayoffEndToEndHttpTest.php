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
use FFB\Scoring\MatchupScoringService;
use FFB\Scoring\ScoringEngine;
use FFB\TeamRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Slice 8 — full brackets from create to Champion across field sizes (including
 * a bye field), plus proof that a playoff week scores through the unchanged
 * Wave 3 pipeline.
 */
final class PlayoffEndToEndHttpTest extends DatabaseTestCase
{
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

    private function seededLeague(int $count): void
    {
        $teams = new TeamRepository($this->pdo);
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $teams->create($this->leagueId, $this->seasonId, 'Team ' . chr(65 + $i));
        }
        $score = 200;
        for ($i = 0; $i < $count - 1; $i += 2) {
            $this->pdo->prepare(
                'INSERT INTO matchups (league_id, season_id, week, round, home_team_id, away_team_id, home_score, away_score, status)'
                . " VALUES (?,?,14,NULL,?,?,?,?,'final')"
            )->execute([$this->leagueId, $this->seasonId, $ids[$i], $ids[$i + 1], $score, $score - 1]);
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

    private function teamName(int $teamId): string
    {
        return (new TeamRepository($this->pdo))->namesForSeason($this->leagueId, $this->seasonId)[$teamId];
    }

    private function round(int $round): array
    {
        return (new MatchupRepository($this->pdo))->forRound($this->seasonId, $round);
    }

    /** Settle every game of a round so the given seeds win (in game order). */
    private function settleRound(int $round, int ...$winnerSeeds): void
    {
        foreach ($this->round($round) as $i => $m) {
            $winnerTeam = $this->seedTeam($winnerSeeds[$i]);
            [$hs, $as] = $winnerTeam === (int) $m['home_team_id'] ? [100.0, 90.0] : [90.0, 100.0];
            $this->pdo->prepare("UPDATE matchups SET home_score=?, away_score=?, status='final' WHERE id=?")
                ->execute([$hs, $as, (int) $m['id']]);
        }
    }

    public function testSixTeamBracketRunsToAChampion(): void
    {
        $this->seededLeague(6);
        $this->dispatch('POST', '/admin/playoffs/create');

        // Round 1 (games): 4v5, 3v6. Higher seeds win. Byes: 1, 2.
        $this->settleRound(1, 4, 3);
        $this->dispatch('POST', '/admin/playoffs/advance');

        // Semis: 1v4, 2v3. Seeds 1 and 2 win.
        $this->assertCount(2, $this->round(2));
        $this->settleRound(2, 1, 2);
        $this->dispatch('POST', '/admin/playoffs/advance');

        // Final: 1v2. Seed 1 wins it all.
        $final = $this->round(3);
        $this->assertCount(1, $final);
        $this->settleRound(3, 1);

        $view = $this->dispatch('GET', '/playoffs');
        $this->assertStringContainsString('champions', $view->body);
        $this->assertStringContainsString($this->teamName($this->seedTeam(1)), $view->body);

        // No round beyond the final.
        $this->assertSame(409, $this->dispatch('POST', '/admin/playoffs/advance')->status);
    }

    public function testEightTeamBracketRunsToAChampion(): void
    {
        $this->seededLeague(8);
        $this->dispatch('POST', '/admin/playoffs/create');

        // Round 1: 1v8, 4v5, 2v7, 3v6 — higher seeds win.
        $this->assertCount(4, $this->round(1));
        $this->settleRound(1, 1, 4, 2, 3);
        $this->dispatch('POST', '/admin/playoffs/advance');

        // Semis: winners in slot order -> 1v4, 2v3.
        $this->assertCount(2, $this->round(2));
        $this->settleRound(2, 1, 2);
        $this->dispatch('POST', '/admin/playoffs/advance');

        // Final: 1v2.
        $this->assertCount(1, $this->round(3));
        $this->settleRound(3, 2); // upset: seed 2 wins the final

        $view = $this->dispatch('GET', '/playoffs');
        $this->assertStringContainsString($this->teamName($this->seedTeam(2)), $view->body);
        $this->assertStringContainsString('champions', $view->body);
    }

    public function testAPlayoffWeekScoresThroughTheWave3Pipeline(): void
    {
        // A 2-team field: Round 1 is the final, at week 15.
        $this->seededLeague(2);
        $this->dispatch('POST', '/admin/playoffs/create');

        $top = $this->seedTeam(1);
        $low = $this->seedTeam(2);

        // Real lineups + stats for the playoff week; no manual score-setting.
        $players = new PlayerRepository($this->pdo);
        $lineups = new LineupRepository($this->pdo);
        $stats = new PlayerWeekStatsRepository($this->pdo);
        foreach ([[$top, 'TOP', 300], [$low, 'LOW', 100]] as [$team, $pid, $yards]) {
            $players->upsert($pid, null, $pid, 'QB', 'KC', 'Active', 1);
            $lineups->replaceForTeamWeek($this->leagueId, $this->seasonId, 15, $team, [
                ['roster_slot' => 'QB', 'slot_index' => 0, 'player_id' => $pid],
            ]);
            $stats->upsert($this->seasonId, 15, $pid, 'sleeper', ['pass_yard' => $yards]);
        }

        // Score the playoff week exactly as the Wave 3 crons do.
        $scoring = new MatchupScoringService(
            new MatchupRepository($this->pdo),
            $lineups,
            $stats,
            new ScoringEngine(),
            new LeagueSettingsRepository($this->pdo),
        );
        $scoring->scoreWeek($this->leagueId, $this->seasonId, 15, 'final');

        // The playoff matchup got real, non-null computed scores.
        $final = $this->round(1)[0];
        $this->assertSame('final', (string) $final['status']);
        $this->assertNotNull($final['home_score']);

        // And the higher-scoring team is crowned champion via the bracket read model.
        $view = $this->dispatch('GET', '/playoffs');
        $this->assertStringContainsString($this->teamName($top), $view->body);
        $this->assertStringContainsString('champions', $view->body);
    }
}
