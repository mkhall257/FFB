<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\RosterRepository;
use FFB\TeamRepository;
use FFB\Transactions\TransactionException;
use FFB\Transactions\TransactionService;
use FFB\View;

/**
 * The Manager-facing Free Agents / Add-Drop page. Lists the unrostered Player
 * pool (searchable, position-filterable) and lets a Manager add a Player,
 * dropping one of their own when their Roster is full. A Manager only ever acts
 * on their own Team — resolved from the session.
 */
final class PlayersController
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly PlayerRepository $players,
        private readonly RosterRepository $rosters,
        private readonly TeamRepository $teams,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        return $this->render($request, $session, null, 200);
    }

    public function add(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $team = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));
        if ($team === null) {
            return $this->render($request, $session, 'You do not manage a team.', 403);
        }

        $addPlayerId = trim((string) $request->input('add_player_id', ''));
        $dropPlayerId = trim((string) $request->input('drop_player_id', ''));

        try {
            $this->transactions->addDrop(
                $leagueId,
                $seasonId,
                (int) $team['id'],
                (int) $session->get('user_id'),
                $addPlayerId,
                $dropPlayerId === '' ? null : $dropPlayerId,
            );
        } catch (TransactionException $e) {
            return $this->render($request, $session, $e->getMessage(), $e->status);
        }

        $session->set('flash', 'Transaction complete.');

        return Response::redirect('/players');
    }

    private function render(Request $request, Session $session, ?string $error, int $status): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $team = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));

        $search = $request->query['q'] ?? null;
        $position = $request->query['pos'] ?? null;
        $available = $this->players->availableForSeason(
            $seasonId,
            is_string($search) ? $search : null,
            is_string($position) ? $position : null,
        );

        $teamId = $team !== null ? (int) $team['id'] : 0;
        $myRoster = $team !== null ? ($this->rosters->byTeam($seasonId)[$teamId] ?? []) : [];

        $flash = $session->get('flash');
        $session->remove('flash');

        return Response::html(
            $this->view->page('players', 'Free Agents', [
                'hasTeam' => $team !== null,
                'available' => $available,
                'myRoster' => $myRoster,
                'cap' => $this->transactions->rosterCap($leagueId, $seasonId),
                'rosterSize' => count($myRoster),
                'search' => is_string($search) ? $search : '',
                'position' => is_string($position) ? $position : '',
                'error' => $error,
                'flash' => $error === null && is_string($flash) ? $flash : null,
            ]),
            $status,
        );
    }
}
