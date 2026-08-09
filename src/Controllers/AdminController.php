<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\TeamRepository;
use FFB\UserRepository;
use FFB\View;
use PDO;
use PDOException;

/**
 * Commissioner-only Team and Manager management: create Teams, provision
 * Manager logins attached to a Team, reset passwords, and activate/deactivate
 * Managers. Successful POSTs redirect back to /admin with a flash message
 * (post/redirect/get); validation failures re-render the page with a 400.
 */
final class AdminController
{
    private const MIN_PASSWORD_LENGTH = 6;

    public function __construct(
        private readonly PDO $pdo,
        private readonly TeamRepository $teams,
        private readonly UserRepository $users,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        $flash = $session->get('flash');
        $session->remove('flash');

        return $this->renderIndex(is_string($flash) ? $flash : null, null);
    }

    public function createTeam(Request $request, Session $session): Response
    {
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->renderIndex(null, 'Team name is required.', 400);
        }

        try {
            $this->teams->create(
                $this->leagues->currentLeagueId(),
                $this->leagues->currentSeasonId(),
                $name,
            );
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return $this->renderIndex(null, "A team named \"{$name}\" already exists.", 400);
            }
            throw $e;
        }

        $session->set('flash', "Created team \"{$name}\".");

        return Response::redirect('/admin');
    }

    public function createManager(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        $teamId = (int) $request->input('team_id', '0');
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');
        $displayName = trim((string) $request->input('display_name', ''));

        if ($username === '' || $displayName === '') {
            return $this->renderIndex(null, 'Username and display name are required.', 400);
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return $this->renderIndex(null, 'Password must be at least 6 characters.', 400);
        }

        $team = $this->teams->find($leagueId, $seasonId, $teamId);
        if ($team === null) {
            return $this->renderIndex(null, 'Choose a valid team.', 400);
        }
        if ($team['user_id'] !== null) {
            return $this->renderIndex(null, 'That team already has a manager.', 400);
        }

        $this->pdo->beginTransaction();
        try {
            $userId = $this->users->create($leagueId, $username, $password, 'manager', $displayName);
            $this->teams->assignManager($teamId, $userId);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ($e->getCode() === '23000') {
                return $this->renderIndex(null, "Username \"{$username}\" is already taken.", 400);
            }
            throw $e;
        }

        $session->set('flash', "Created manager \"{$username}\".");

        return Response::redirect('/admin');
    }

    public function resetPassword(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $userId = (int) $request->input('user_id', '0');
        $password = (string) $request->input('password', '');

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return $this->renderIndex(null, 'Password must be at least 6 characters.', 400);
        }

        $user = $this->users->findById($userId);
        if ($user === null || (int) $user['league_id'] !== $leagueId) {
            return $this->renderIndex(null, 'Unknown user.', 400);
        }

        $this->users->resetPassword($leagueId, $userId, $password);
        $session->set('flash', "Password reset for \"{$user['username']}\".");

        return Response::redirect('/admin');
    }

    public function setManagerStatus(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $userId = (int) $request->input('user_id', '0');
        $active = $request->input('active', '0') === '1';

        $user = $this->users->findById($userId);
        if ($user === null || (int) $user['league_id'] !== $leagueId) {
            return $this->renderIndex(null, 'Unknown user.', 400);
        }

        $this->users->setActive($leagueId, $userId, $active);
        $verb = $active ? 'Reactivated' : 'Deactivated';
        $session->set('flash', "{$verb} \"{$user['username']}\".");

        return Response::redirect('/admin');
    }

    private function renderIndex(?string $flash, ?string $error, int $status = 200): Response
    {
        $teams = $this->teams->listWithManagers(
            $this->leagues->currentLeagueId(),
            $this->leagues->currentSeasonId(),
        );

        return Response::html(
            $this->view->page('admin', 'Commissioner tools', [
                'teams' => $teams,
                'flash' => $flash,
                'error' => $error,
            ], '', '', 'layout_app'),
            $status,
        );
    }
}
