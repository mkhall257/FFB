<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\Tests\Support\DatabaseTestCase;

/**
 * Exercises Commissioner Draft configuration through the HTTP seam: setting the
 * pick timer, the expiry-triggered Auto-pick toggle, an optional display date,
 * and the roster shape.
 */
final class DraftConfigHttpTest extends DatabaseTestCase
{
    private function leagueId(): int
    {
        return (new LeagueRepository($this->pdo))->currentLeagueId();
    }

    private function commissioner(): ArraySession
    {
        return new ArraySession([
            'user_id' => 1,
            'role' => 'commissioner',
            'league_id' => $this->leagueId(),
            'display_name' => 'Boss',
        ]);
    }

    /**
     * @param array<string,mixed> $post
     * @return array{0:Response,1:ArraySession}
     */
    private function dispatch(string $method, string $path, array $post = [], ?ArraySession $session = null): array
    {
        $session ??= $this->commissioner();
        $response = Kernel::router($this->pdo)
            ->dispatch(new Request($method, $path, $post), $session);

        return [$response, $session];
    }

    private function draftRow(): ?array
    {
        $row = $this->pdo->query('SELECT * FROM drafts')->fetch();

        return $row === false ? null : $row;
    }

    private function setting(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM league_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function testCommissionerCanSetTimerToggleAndDate(): void
    {
        [$response] = $this->dispatch('POST', '/admin/draft/config', [
            'pick_seconds' => '90',
            'autopick_on_expiry' => '0',
            'scheduled_at' => '2026-09-01 18:00',
        ]);

        $this->assertSame(302, $response->status);

        $draft = $this->draftRow();
        $this->assertNotNull($draft, 'configuring should create the season Draft');
        $this->assertSame(90, (int) $draft['pick_seconds']);
        $this->assertSame(0, (int) $draft['autopick_on_expiry']);
        $this->assertSame('setup', $draft['state']);
        $this->assertStringStartsWith('2026-09-01 18:00', (string) $draft['scheduled_at']);
    }

    public function testAutopickToggleDefaultsOnWhenNotSubmitted(): void
    {
        [$response] = $this->dispatch('POST', '/admin/draft/config', [
            'pick_seconds' => '120',
            'autopick_on_expiry' => '1',
        ]);

        $this->assertSame(302, $response->status);
        $this->assertSame(1, (int) $this->draftRow()['autopick_on_expiry']);
    }

    public function testTimerBelowMinimumIsRejected(): void
    {
        [$response] = $this->dispatch('POST', '/admin/draft/config', [
            'pick_seconds' => '3',
            'autopick_on_expiry' => '1',
        ]);

        $this->assertSame(400, $response->status);
        $this->assertNull($this->draftRow(), 'an invalid config must not create a Draft');
    }

    public function testCommissionerCanSetRosterShape(): void
    {
        [$response] = $this->dispatch('POST', '/admin/draft/config', [
            'pick_seconds' => '120',
            'autopick_on_expiry' => '1',
            'roster_qb' => '1',
            'roster_rb' => '2',
            'roster_wr' => '3',
            'roster_te' => '1',
            'roster_flex' => '1',
            'roster_k' => '1',
            'roster_def' => '1',
            'roster_bench' => '5',
        ]);

        $this->assertSame(302, $response->status);
        $this->assertSame('3', $this->setting('roster.wr'));
        $this->assertSame('5', $this->setting('roster.bench'));
    }

    public function testManagerCannotConfigureDraft(): void
    {
        $manager = new ArraySession([
            'user_id' => 9, 'role' => 'manager', 'league_id' => $this->leagueId(), 'display_name' => 'Kid',
        ]);

        [$response] = $this->dispatch('POST', '/admin/draft/config', ['pick_seconds' => '120'], $manager);

        $this->assertSame(403, $response->status);
        $this->assertNull($this->draftRow());
    }
}
