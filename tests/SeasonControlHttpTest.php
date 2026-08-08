<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\Tests\Support\DatabaseTestCase;

final class SeasonControlHttpTest extends DatabaseTestCase
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

    private function setting(string $key): ?string
    {
        return (new LeagueSettingsRepository($this->pdo))->all($this->leagueId, $this->seasonId)[$key] ?? null;
    }

    public function testPageRendersForCommissioner(): void
    {
        $response = $this->dispatch($this->commissioner(), 'GET', '/admin/season');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Season control', $response->body);
        // Scoring rules show friendly labels in the visible <label>, not raw keys.
        $this->assertStringContainsString('Passing touchdown', $response->body);
        $this->assertStringContainsString('Defense — 1 to 6 points allowed', $response->body);
        $this->assertStringNotContainsString('>def_pa_1_6<', $response->body);
    }

    public function testManagerIsForbidden(): void
    {
        $response = $this->dispatch($this->manager(), 'GET', '/admin/season');
        $this->assertSame(403, $response->status);
    }

    public function testStartWeekSetsCurrentWeekYearAndKickoff(): void
    {
        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/season/week', [
            'season_year' => '2026',
            'week' => '1',
            'kickoff' => '2026-09-10T20:20',
        ]);

        $this->assertSame(302, $response->status);
        $this->assertSame('1', $this->setting('schedule.current_week'));
        $this->assertSame('2026', $this->setting('schedule.season_year'));
        // Stored as an ISO 8601 string with a timezone offset.
        $this->assertMatchesRegularExpression(
            '/^2026-09-10T20:20:00[-+]\d{2}:\d{2}$/',
            (string) $this->setting('schedule.week_1_kickoff'),
        );
    }

    public function testStartWeekRejectsAnOutOfRangeWeek(): void
    {
        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/season/week', [
            'season_year' => '2026', 'week' => '99', 'kickoff' => '2026-09-10T20:20',
        ]);

        $this->assertSame(400, $response->status);
        $this->assertNull($this->setting('schedule.current_week'));
    }

    public function testSaveScoringUpdatesSettings(): void
    {
        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/season/scoring', [
            'scoring' => ['reception' => '1', 'pass_td' => '6'],
        ]);

        $this->assertSame(302, $response->status);
        $this->assertSame('1', $this->setting('scoring.reception'));
        $this->assertSame('6', $this->setting('scoring.pass_td'));
    }

    public function testSaveScoringRejectsNonNumeric(): void
    {
        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/season/scoring', [
            'scoring' => ['reception' => 'lots'],
        ]);

        $this->assertSame(400, $response->status);
        $this->assertSame('0.5', $this->setting('scoring.reception')); // unchanged seed default
    }

    public function testSaveRosterCoercesToNonNegativeIntegers(): void
    {
        $response = $this->dispatch($this->commissioner(), 'POST', '/admin/season/roster', [
            'roster' => ['wr' => '3', 'bench' => '7'],
        ]);

        $this->assertSame(302, $response->status);
        $this->assertSame('3', $this->setting('roster.wr'));
        $this->assertSame('7', $this->setting('roster.bench'));
    }
}
