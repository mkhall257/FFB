<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Draft\DraftPickException;
use FFB\Draft\DraftService;
use FFB\DraftPickRepository;
use FFB\DraftQueueRepository;
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
    /** Draft states in which a Manager may build their Queue. */
    private const QUEUE_OPEN_STATES = ['ready', 'live', 'paused'];

    public function __construct(
        private readonly DraftService $service,
        private readonly DraftRepository $drafts,
        private readonly DraftPickRepository $picks,
        private readonly DraftQueueRepository $queues,
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

        // Polling drives the clock: any load of the room resolves an expired
        // pick (see ADR-0003, ADR-0007).
        $draft = $this->drafts->find($this->leagues->currentLeagueId(), $this->leagues->currentSeasonId());
        if ($draft !== null) {
            $this->service->processExpiryIfDue($draft);
        }

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

    public function addToQueue(Request $request, Session $session): Response
    {
        [$draft, $team, $error] = $this->queueContext($session);
        if ($error !== null) {
            return $error;
        }

        $playerId = trim((string) $request->input('player_id', ''));
        if ($playerId === '' || !$this->players->isDraftable($playerId)) {
            return $this->renderRoom($session, null, 'That player cannot be queued.', 400);
        }

        $ids = $this->queues->playerIds((int) $draft['id'], (int) $team['id']);
        if (!in_array($playerId, $ids, true)) {
            $ids[] = $playerId;
            $this->queues->setQueue((int) $draft['id'], (int) $team['id'], $ids);
        }

        return Response::redirect('/draft');
    }

    public function removeFromQueue(Request $request, Session $session): Response
    {
        [$draft, $team, $error] = $this->queueContext($session);
        if ($error !== null) {
            return $error;
        }

        $playerId = trim((string) $request->input('player_id', ''));
        $ids = array_values(array_filter(
            $this->queues->playerIds((int) $draft['id'], (int) $team['id']),
            static fn (string $id): bool => $id !== $playerId,
        ));
        $this->queues->setQueue((int) $draft['id'], (int) $team['id'], $ids);

        return Response::redirect('/draft');
    }

    public function reorderQueue(Request $request, Session $session): Response
    {
        [$draft, $team, $error] = $this->queueContext($session);
        if ($error !== null) {
            return $error;
        }

        $submitted = $request->post['player_ids'] ?? [];
        $submitted = is_array($submitted) ? $submitted : [];

        $clean = [];
        foreach ($submitted as $value) {
            $playerId = trim((string) $value);
            if ($playerId === '' || !$this->players->isDraftable($playerId)) {
                return $this->renderRoom($session, null, 'That queue contains a player who cannot be drafted.', 400);
            }
            if (!in_array($playerId, $clean, true)) {
                $clean[] = $playerId;
            }
        }

        $this->queues->setQueue((int) $draft['id'], (int) $team['id'], $clean);

        return Response::redirect('/draft');
    }

    /**
     * Resolve the current Draft and the acting Manager's Team for a queue
     * action, or return an error response.
     *
     * @return array{0:array<string,mixed>|null,1:array<string,mixed>|null,2:Response|null}
     */
    private function queueContext(Session $session): array
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        $draft = $this->drafts->find($leagueId, $seasonId);
        if ($draft === null || !in_array($draft['state'], self::QUEUE_OPEN_STATES, true)) {
            return [null, null, $this->renderRoom($session, null, 'The queue is not open right now.', 409)];
        }

        $team = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));
        if ($team === null) {
            return [null, null, $this->renderRoom($session, null, 'You do not manage a team in this draft.', 403)];
        }

        return [$draft, $team, null];
    }

    private function renderRoom(Session $session, ?string $flash, ?string $error, int $status = 200): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $draft = $this->drafts->find($leagueId, $seasonId);
        $myTeam = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));

        $board = [];
        $available = [];
        $myQueue = [];
        $onClockTeamId = null;
        $myTurn = false;

        if ($draft !== null && in_array($draft['state'], ['live', 'paused', 'complete'], true)) {
            $board = $this->picks->board((int) $draft['id']);
        }

        if ($draft !== null && in_array($draft['state'], self::QUEUE_OPEN_STATES, true)) {
            $available = $this->players->availableForDraft((int) $draft['id']);
            if ($myTeam !== null) {
                $myQueue = $this->queues->queued((int) $draft['id'], (int) $myTeam['id']);
            }
        }

        if ($draft !== null && $draft['state'] === 'live' && $draft['current_pick_no'] !== null) {
            $current = $this->picks->findByOverall((int) $draft['id'], (int) $draft['current_pick_no']);
            $onClockTeamId = $current !== null ? (int) $current['team_id'] : null;
            $myTurn = $myTeam !== null && $onClockTeamId === (int) $myTeam['id'];
        }

        return Response::html(
            $this->view->page('draft_room', 'Draft room', [
                'draft' => $draft,
                'board' => $board,
                'available' => $available,
                'myQueue' => $myQueue,
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
