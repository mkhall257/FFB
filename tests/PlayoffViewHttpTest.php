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
 * Slice 6 — the read-only /playoffs bracket view: round labels, seeds, byes,
 * scores, and the Champion once the final is settled. Visible to any Manager.
 */
final class PlayoffViewHttpTest extends DatabaseTestCase
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

    private function session(string $role): ArraySession
    {
        return new ArraySession([
            'user_id' => $role === 'commissioner' ? 9999 : 1, 'role' => $role,
            'league_id' => $this->leagueId, 'display_name' => 'U',
        ]);
    }

    private function dispatch(ArraySession $session, string $method, string $path, array $post = []): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $session);
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

    private function settleRound(int $round, int ...$winnerSeeds): void
    {
        $games = (new MatchupRepository($this->pdo))->forRound($this->seasonId, $round);
        foreach ($games as $i => $m) {
            $winnerTeam = $this->seedTeam($winnerSeeds[$i]);
            [$hs, $as] = $winnerTeam === (int) $m['home_team_id'] ? [100.0, 90.0] : [90.0, 100.0];
            $this->pdo->prepare("UPDATE matchups SET home_score=?, away_score=?, status='final' WHERE id=?")
                ->execute([$hs, $as, (int) $m['id']]);
        }
    }

    public function testBeforeCreationShowsAPlaceholder(): void
    {
        $response = $this->dispatch($this->session('manager'), 'GET', '/playoffs');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString("hasn't been created", $response->body);
    }

    public function testShowsSeededTeamsAndRoundLabels(): void
    {
        $this->seededLeague(4);
        $this->dispatch($this->session('commissioner'), 'POST', '/admin/playoffs/create');

        $response = $this->dispatch($this->session('manager'), 'GET', '/playoffs');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Semifinals', $response->body); // round 1 of 2
        $this->assertStringContainsString($this->teamName($this->seedTeam(1)), $response->body);
        $this->assertStringContainsString($this->teamName($this->seedTeam(4)), $response->body);
    }

    public function testShowsByesForANonPowerOfTwoField(): void
    {
        $this->seededLeague(6);
        $this->dispatch($this->session('commissioner'), 'POST', '/admin/playoffs/create');

        $response = $this->dispatch($this->session('manager'), 'GET', '/playoffs');
        $this->assertStringContainsString('Bye:', $response->body);
        // Top two seeds sit out round 1.
        $this->assertStringContainsString($this->teamName($this->seedTeam(1)), $response->body);
    }

    public function testShowsChampionOnceTheFinalIsSettled(): void
    {
        $this->seededLeague(4);
        $commish = $this->session('commissioner');
        $this->dispatch($commish, 'POST', '/admin/playoffs/create');
        $this->settleRound(1, 1, 2);              // semis: seeds 1 and 2 win
        $this->dispatch($commish, 'POST', '/admin/playoffs/advance');
        $this->settleRound(2, 1);                 // final: seed 1 wins

        $response = $this->dispatch($this->session('manager'), 'GET', '/playoffs');
        $this->assertStringContainsString('champions', $response->body);
        $this->assertStringContainsString($this->teamName($this->seedTeam(1)), $response->body);
        $this->assertStringContainsString('🏆', $response->body);
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $response = $this->dispatch(new ArraySession([]), 'GET', '/playoffs');
        $this->assertSame('/login', $response->headers['Location'] ?? null);
    }
}
