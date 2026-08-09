<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\UserRepository;
use FFB\View;

/**
 * Self-service account page for any signed-in user (Commissioner or Manager):
 * change the display name shown across the app, and optionally the password
 * (which requires confirming the current one). Successful POSTs redirect back
 * with a flash (post/redirect/get); validation failures re-render with a 400.
 */
final class ProfileController
{
    private const MIN_PASSWORD_LENGTH = 6;

    public function __construct(
        private readonly UserRepository $users,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function show(Request $request, Session $session): Response
    {
        $flash = $session->get('flash');
        $session->remove('flash');

        return $this->render($session, is_string($flash) ? $flash : null, null);
    }

    public function update(Request $request, Session $session): Response
    {
        $userId = (int) $session->get('user_id');
        $user = $this->users->findById($userId);
        if ($user === null) {
            return Response::redirect('/login');
        }

        $displayName = trim((string) $request->input('display_name', ''));
        if ($displayName === '') {
            return $this->render($session, null, 'Display name is required.', 400);
        }

        // Password change is optional: only acted on when a new password is given.
        $newPassword = (string) $request->input('new_password', '');
        if ($newPassword !== '') {
            $current = (string) $request->input('current_password', '');
            if (!password_verify($current, (string) $user['password_hash'])) {
                return $this->render($session, null, 'Your current password is incorrect.', 400);
            }
            if (strlen($newPassword) < self::MIN_PASSWORD_LENGTH) {
                return $this->render($session, null, 'New password must be at least 6 characters.', 400);
            }
            $this->users->resetPassword($this->leagues->currentLeagueId(), $userId, $newPassword);
        }

        if ($displayName !== (string) $user['display_name']) {
            $this->users->updateDisplayName($userId, $displayName);
            // Refresh the chrome's "Signed in as …" immediately.
            $session->set('display_name', $displayName);
        }

        $session->set('flash', 'Profile updated.');

        return Response::redirect('/profile');
    }

    private function render(Session $session, ?string $flash, ?string $error, int $status = 200): Response
    {
        $user = $this->users->findById((int) $session->get('user_id'));

        return Response::html(
            $this->view->page('profile', 'My Profile', [
                'displayName' => (string) ($user['display_name'] ?? $session->get('display_name', '')),
                'username' => (string) ($user['username'] ?? ''),
                'flash' => $flash,
                'error' => $error,
            ], '', '', 'layout_app'),
            $status,
        );
    }
}
