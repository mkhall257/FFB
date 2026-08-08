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
 * Slice 7 — Commissioner correct-last-round (undo the latest advancement) and
 * reset (only before any playoff game is played), both tightly gated.
 */
final class PlayoffCommishControlsHttpTest extends DatabaseTestCase
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

    private function manager(): ArraySession
    {
        return new ArraySession([
            'user_id' => 1, 'role' => 'manager',
            'league_id' => $this->leagueId, 'display_name' => 'Kid',
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

    private function round(int $round): array
    {
        return (new MatchupRepository($this->pdo))->forRound($this->seasonId, $round);
    }

    private function settleRound(int $round, int ...$winnerSeeds): void
    {
        foreach ($this->round($round) as $i => $m) {
            $winnerTeam = $this->seedTeam($winnerSeeds[$i]);
            [$hs, $as] = $winnerTeam === (int) $m['home_team_id'] ? [100.0, 90.0] : [90.0, 100.0];
            $this->pdo->prepare("UPDATE matchups SET home_score=?, away_score=?, status='final' WHERE id=?")
                ->execute([$hs, $as, (int) $m['id']]);
        }
    }

    private function currentWeek(): string
    {
        return (new LeagueSettingsRepository($this->pdo))
            ->all($this->leagueId, $this->seasonId)['schedule.current_week'] ?? '';
    }

    private function hasBracket(): bool
    {
        return (new PlayoffRepository($this->pdo))->hasBracket($this->seasonId);
    }

    // --- reset ---

    public function testResetClearsAnUnplayedBracket(): void
    {
        $this->seededLeague(4);
        $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/create');
        $this->assertTrue($this->hasBracket());

        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/reset');
        $this->assertSame(302, $response->status);
        $this->assertFalse($this->hasBracket());
        $this->assertSame([], $this->round(1));
    }

    public function testResetRefusedOnceAGameIsFinal(): void
    {
        $this->seededLeague(4);
        $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/create');
        $this->settleRound(1, 1, 2); // games played

        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/reset');
        $this->assertSame(409, $response->status);
        $this->assertTrue($this->hasBracket());
    }

    public function testResetCanReconfigureTheField(): void
    {
        $this->seededLeague(6);
        $commish = $this->commissioner();
        $this->dispatch($commish, 'POST', '/admin/playoffs/create');
        $this->dispatch($commish, 'POST', '/admin/playoffs/reset');

        // Change field size and re-create.
        $this->dispatch($commish, 'POST', '/admin/season/playoffs', ['team_count' => '4']);
        $this->dispatch($commish, 'POST', '/admin/playoffs/create');

        $this->assertCount(4, (new PlayoffRepository($this->pdo))->seeds($this->seasonId));
    }

    public function testResetRefusedWithoutBracket(): void
    {
        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/reset');
        $this->assertSame(409, $response->status);
    }

    // --- correct last round ---

    public function testCorrectUndoesTheLatestAdvancement(): void
    {
        $this->seededLeague(4);
        $commish = $this->commissioner();
        $this->dispatch($commish, 'POST', '/admin/playoffs/create');
        $this->settleRound(1, 1, 2);
        $this->dispatch($commish, 'POST', '/admin/playoffs/advance'); // opens round 2 (final)
        $this->assertCount(1, $this->round(2));

        $response = $this->dispatch($commish, 'POST', '/admin/playoffs/correct');
        $this->assertSame(302, $response->status);
        $this->assertSame([], $this->round(2));   // round 2 removed
        $this->assertCount(2, $this->round(1));    // round 1 intact
        $this->assertSame('15', $this->currentWeek()); // back to round 1's week
    }

    public function testCorrectThenReadvanceReflectsCorrectedScores(): void
    {
        $this->seededLeague(4);
        $commish = $this->commissioner();
        $this->dispatch($commish, 'POST', '/admin/playoffs/create');
        // Originally seed 1 and seed 2 win.
        $this->settleRound(1, 1, 2);
        $this->dispatch($commish, 'POST', '/admin/playoffs/advance');

        // Undo, then flip game 0 so seed 4 wins instead, and re-advance.
        $this->dispatch($commish, 'POST', '/admin/playoffs/correct');
        $this->settleRound(1, 4, 2);
        $this->dispatch($commish, 'POST', '/admin/playoffs/advance');

        $final = $this->round(2);
        $this->assertCount(1, $final);
        $this->assertSame($this->seedTeam(4), (int) $final[0]['home_team_id']);
    }

    public function testCorrectRefusedBeforeAnyAdvancement(): void
    {
        $this->seededLeague(4);
        $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/create');

        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/playoffs/correct');
        $this->assertSame(409, $response->status);
        $this->assertCount(2, $this->round(1)); // round 1 untouched
    }

    // --- role gate ---

    public function testManagerCannotDriveTheBracket(): void
    {
        $this->assertSame(403, $this->dispatch($this->manager(), 'POST', '/admin/playoffs/reset')->status);
        $this->assertSame(403, $this->dispatch($this->manager(), 'POST', '/admin/playoffs/correct')->status);
    }
}
