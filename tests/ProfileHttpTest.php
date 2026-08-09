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
 * Self-service profile: a signed-in user can change their display name and,
 * with the current password, their password.
 */
final class ProfileHttpTest extends DatabaseTestCase
{
    private function leagueId(): int
    {
        return (new LeagueRepository($this->pdo))->currentLeagueId();
    }

    /** Provision a team + manager through the admin flow; return the manager's user id. */
    private function makeManager(string $username = 'ada', string $password = 'swimfast', string $display = 'Ada'): int
    {
        $commissioner = new ArraySession([
            'user_id' => 1, 'role' => 'commissioner', 'league_id' => $this->leagueId(), 'display_name' => 'Boss',
        ]);
        $router = Kernel::router($this->pdo);
        $router->dispatch(new Request('POST', '/admin/teams', ['name' => 'Sharks']), $commissioner);
        $teamId = (int) $this->pdo->query("SELECT id FROM teams WHERE name = 'Sharks'")->fetchColumn();
        $router->dispatch(new Request('POST', '/admin/managers', [
            'team_id' => $teamId, 'display_name' => $display, 'username' => $username, 'password' => $password,
        ]), $commissioner);

        return (int) $this->pdo->query("SELECT id FROM users WHERE username = '{$username}'")->fetchColumn();
    }

    private function managerSession(int $userId, string $display = 'Ada'): ArraySession
    {
        return new ArraySession([
            'user_id' => $userId, 'role' => 'manager', 'league_id' => $this->leagueId(), 'display_name' => $display,
        ]);
    }

    /** @param array<string,mixed> $post */
    private function dispatch(string $method, string $path, array $post, ArraySession $session): Response
    {
        return Kernel::router($this->pdo)->dispatch(new Request($method, $path, $post), $session);
    }

    private function canLogIn(string $username, string $password): bool
    {
        $session = new ArraySession();
        Kernel::router($this->pdo)->dispatch(
            new Request('POST', '/login', ['username' => $username, 'password' => $password]),
            $session,
        );

        return $session->get('user_id') !== null;
    }

    public function testUpdatesDisplayNameAndRefreshesSession(): void
    {
        $uid = $this->makeManager();
        $session = $this->managerSession($uid);

        $response = $this->dispatch('POST', '/profile', ['display_name' => 'Ada New'], $session);

        $this->assertSame(302, $response->status);
        $this->assertSame(
            'Ada New',
            (string) $this->pdo->query("SELECT display_name FROM users WHERE id = {$uid}")->fetchColumn(),
        );
        $this->assertSame('Ada New', $session->get('display_name'));
    }

    public function testChangesPasswordWithCorrectCurrentPassword(): void
    {
        $uid = $this->makeManager();
        $session = $this->managerSession($uid);

        $response = $this->dispatch('POST', '/profile', [
            'display_name' => 'Ada', 'current_password' => 'swimfast', 'new_password' => 'newpass1',
        ], $session);

        $this->assertSame(302, $response->status);
        $this->assertFalse($this->canLogIn('ada', 'swimfast'), 'old password should stop working');
        $this->assertTrue($this->canLogIn('ada', 'newpass1'), 'new password should work');
    }

    public function testRejectsPasswordChangeWithWrongCurrentPassword(): void
    {
        $uid = $this->makeManager();
        $session = $this->managerSession($uid);

        $response = $this->dispatch('POST', '/profile', [
            'display_name' => 'Ada', 'current_password' => 'wrongpass', 'new_password' => 'newpass1',
        ], $session);

        $this->assertSame(400, $response->status);
        $this->assertTrue($this->canLogIn('ada', 'swimfast'), 'password must be unchanged');
    }

    public function testRejectsShortNewPassword(): void
    {
        $uid = $this->makeManager();
        $session = $this->managerSession($uid);

        $response = $this->dispatch('POST', '/profile', [
            'display_name' => 'Ada', 'current_password' => 'swimfast', 'new_password' => 'abc',
        ], $session);

        $this->assertSame(400, $response->status);
        $this->assertTrue($this->canLogIn('ada', 'swimfast'));
    }

    public function testRequiresDisplayName(): void
    {
        $uid = $this->makeManager();
        $session = $this->managerSession($uid);

        $response = $this->dispatch('POST', '/profile', ['display_name' => '   '], $session);

        $this->assertSame(400, $response->status);
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $response = $this->dispatch('GET', '/profile', [], new ArraySession());

        $this->assertSame(302, $response->status);
        $this->assertSame('/login', $response->headers['Location']);
    }
}
