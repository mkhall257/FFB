<?php

declare(strict_types=1);

namespace FFB;

use FFB\Http\Session;

/**
 * Authenticates users against stored password hashes and manages the
 * authenticated session state.
 */
final class Auth
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * Verify credentials and, on success, establish the session. Returns
     * whether authentication succeeded.
     */
    public function attempt(Session $session, int $leagueId, string $username, string $password): bool
    {
        $user = $this->users->findActiveByUsername($leagueId, $username);
        if ($user === null) {
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        $session->regenerate();
        $session->set('user_id', (int) $user['id']);
        $session->set('role', (string) $user['role']);
        $session->set('league_id', (int) $user['league_id']);
        $session->set('display_name', (string) $user['display_name']);

        return true;
    }

    public function logout(Session $session): void
    {
        $session->clear();
    }
}
