<?php

declare(strict_types=1);

namespace FFB\Tests;

use FFB\Http\ArraySession;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Kernel;
use FFB\LeagueRepository;
use FFB\Tests\Support\DatabaseTestCase;
use FFB\UserRepository;

/**
 * Exercises authentication and the role gate through the HTTP seam: synthetic
 * requests dispatched against the real Kernel wiring and a migrated throwaway
 * database.
 */
final class AuthHttpTest extends DatabaseTestCase
{
    private function leagueId(): int
    {
        return (new LeagueRepository($this->pdo))->currentLeagueId();
    }

    private function users(): UserRepository
    {
        return new UserRepository($this->pdo);
    }

    /**
     * @param array<string,mixed> $post
     * @return array{0:Response,1:ArraySession}
     */
    private function dispatch(string $method, string $path, array $post = [], ?ArraySession $session = null): array
    {
        $session ??= new ArraySession();
        $response = Kernel::router($this->pdo)
            ->dispatch(new Request($method, $path, $post), $session);

        return [$response, $session];
    }

    public function testLoginPageIsPublic(): void
    {
        [$response] = $this->dispatch('GET', '/login');

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Log in', $response->body);
    }

    public function testWrongPasswordIsRejectedAndNotAuthenticated(): void
    {
        $this->users()->create($this->leagueId(), 'commish', 'correct-horse', 'commissioner', 'Commish');

        [$response, $session] = $this->dispatch('POST', '/login', [
            'username' => 'commish',
            'password' => 'wrong-password',
        ]);

        $this->assertSame(401, $response->status);
        $this->assertNull($session->get('user_id'));
    }

    public function testCorrectLoginEstablishesSessionAndRedirects(): void
    {
        $this->users()->create($this->leagueId(), 'commish', 'correct-horse', 'commissioner', 'Commish');

        [$response, $session] = $this->dispatch('POST', '/login', [
            'username' => 'commish',
            'password' => 'correct-horse',
        ]);

        $this->assertSame(302, $response->status);
        $this->assertSame('/', $response->headers['Location']);
        $this->assertNotNull($session->get('user_id'));
        $this->assertSame('commissioner', $session->get('role'));
    }

    public function testInactiveUserCannotLogIn(): void
    {
        $id = $this->users()->create($this->leagueId(), 'benched', 'pw-123456', 'manager', 'Benched');
        $this->pdo->exec('UPDATE users SET is_active = 0 WHERE id = ' . $id);

        [$response, $session] = $this->dispatch('POST', '/login', [
            'username' => 'benched',
            'password' => 'pw-123456',
        ]);

        $this->assertSame(401, $response->status);
        $this->assertNull($session->get('user_id'));
    }

    public function testProtectedRouteRedirectsAnonymousToLogin(): void
    {
        [$response] = $this->dispatch('GET', '/');

        $this->assertSame(302, $response->status);
        $this->assertSame('/login', $response->headers['Location']);
    }

    public function testCommissionerRouteIsForbiddenForManager(): void
    {
        $session = new ArraySession([
            'user_id' => 5,
            'role' => 'manager',
            'league_id' => 1,
            'display_name' => 'Kid',
        ]);

        [$response] = $this->dispatch('GET', '/admin', [], $session);

        $this->assertSame(403, $response->status);
    }

    public function testCommissionerRouteIsAllowedForCommissioner(): void
    {
        $session = new ArraySession([
            'user_id' => 1,
            'role' => 'commissioner',
            'league_id' => 1,
            'display_name' => 'Boss',
        ]);

        [$response] = $this->dispatch('GET', '/admin', [], $session);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Commissioner tools', $response->body);
    }

    public function testHomeGreetsLoggedInManager(): void
    {
        $session = new ArraySession([
            'user_id' => 5,
            'role' => 'manager',
            'league_id' => 1,
            'display_name' => 'Kid Manager',
        ]);

        [$response] = $this->dispatch('GET', '/', [], $session);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('Kid Manager', $response->body);
    }

    public function testLogoutClearsSession(): void
    {
        $session = new ArraySession([
            'user_id' => 1,
            'role' => 'commissioner',
            'league_id' => 1,
            'display_name' => 'Boss',
        ]);

        [$response, $session] = $this->dispatch('POST', '/logout', [], $session);

        $this->assertSame(302, $response->status);
        $this->assertSame('/login', $response->headers['Location']);
        $this->assertNull($session->get('user_id'));
    }

    public function testPasswordsAreStoredHashed(): void
    {
        $this->users()->create($this->leagueId(), 'commish', 'super-secret', 'commissioner', 'Commish');

        $hash = (string) $this->pdo
            ->query("SELECT password_hash FROM users WHERE username = 'commish'")
            ->fetchColumn();

        $this->assertNotSame('super-secret', $hash);
        $this->assertTrue(password_verify('super-secret', $hash));
    }
}
