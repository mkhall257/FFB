<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Draft\DraftPickException;
use FFB\Draft\DraftService;
use FFB\DraftPickRepository;
use FFB\DraftRepository;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\PlayerRepository;
use FFB\TeamRepository;
use FFB\View;

/**
 * The live draft room, for any logged-in league member (Manager or
 * Commissioner). Managers pick for their own Team when on the clock; the
 * full board and available Players are visible to everyone in the league.
 */
final class DraftRoomController
{
    public function __construct(
        private readonly DraftService $service,
        private readonly DraftRepository $drafts,
        private readonly DraftPickRepository $picks,
        private readonly TeamRepository $teams,
        private readonly PlayerRepository $players,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        $flash = $session->get('flash');
        $session->remove('flash');

        return $this->renderRoom($session, is_string($flash) ? $flash : null, null);
    }

    public function pick(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        $draft = $this->drafts->find($leagueId, $seasonId);
        if ($draft === null) {
            return $this->renderRoom($session, null, 'There is no draft yet.', 409);
        }

        $team = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));
        if ($team === null) {
            return $this->renderRoom($session, null, 'You do not manage a team in this draft.', 403);
        }

        try {
            $this->service->pick($draft, (int) $team['id'], (string) $request->input('player_id', ''), 'manual');
        } catch (DraftPickException $e) {
            return $this->renderRoom($session, null, $e->getMessage(), $e->status);
        }

        $session->set('flash', 'Pick made.');

        return Response::redirect('/draft');
    }

    private function renderRoom(Session $session, ?string $flash, ?string $error, int $status = 200): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $draft = $this->drafts->find($leagueId, $seasonId);
        $myTeam = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));

        $board = [];
        $available = [];
        $onClockTeamId = null;
        $myTurn = false;

        if ($draft !== null && in_array($draft['state'], ['live', 'paused', 'complete'], true)) {
            $board = $this->picks->board((int) $draft['id']);
        }

        if ($draft !== null && $draft['state'] === 'live' && $draft['current_pick_no'] !== null) {
            $current = $this->picks->findByOverall((int) $draft['id'], (int) $draft['current_pick_no']);
            $onClockTeamId = $current !== null ? (int) $current['team_id'] : null;
            $available = $this->players->availableForDraft((int) $draft['id']);
            $myTurn = $myTeam !== null && $onClockTeamId === (int) $myTeam['id'];
        }

        return Response::html(
            $this->view->page('draft_room', 'Draft room', [
                'draft' => $draft,
                'board' => $board,
                'available' => $available,
                'myTeam' => $myTeam,
                'onClockTeamId' => $onClockTeamId,
                'myTurn' => $myTurn,
                'flash' => $flash,
                'error' => $error,
            ]),
            $status,
        );
    }
}
