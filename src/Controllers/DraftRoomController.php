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
use FFB\LeagueSettingsRepository;
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

    /** Positions a Manager can filter the available pool by, in draft-value order. */
    private const FILTER_POSITIONS = ['QB', 'RB', 'WR', 'TE', 'K', 'DEF'];

    public function __construct(
        private readonly DraftService $service,
        private readonly DraftRepository $drafts,
        private readonly DraftPickRepository $picks,
        private readonly DraftQueueRepository $queues,
        private readonly TeamRepository $teams,
        private readonly PlayerRepository $players,
        private readonly LeagueRepository $leagues,
        private readonly LeagueSettingsRepository $settings,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        $flash = $session->get('flash');
        $session->remove('flash');

        // Polling drives the clock: any load of the room resolves a pre-staged
        // auto-start whose time has arrived, then an expired pick (ADR-0003,
        // ADR-0007).
        $this->service->startScheduledIfDue();
        $draft = $this->drafts->find($this->leagues->currentLeagueId(), $this->leagues->currentSeasonId());
        if ($draft !== null) {
            $this->service->processExpiryIfDue($draft);
        }

        return $this->renderRoom($request, $session, is_string($flash) ? $flash : null, null);
    }

    public function pick(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        $draft = $this->drafts->find($leagueId, $seasonId);
        if ($draft === null) {
            return $this->renderRoom($request, $session, null, 'There is no draft yet.', 409);
        }

        $team = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));
        if ($team === null) {
            return $this->renderRoom($request, $session, null, 'You do not manage a team in this draft.', 403);
        }

        try {
            $this->service->pick($draft, (int) $team['id'], (string) $request->input('player_id', ''), 'manual');
        } catch (DraftPickException $e) {
            return $this->renderRoom($request, $session, null, $e->getMessage(), $e->status);
        }

        // If the next Team(s) are in Auto-draft mode, let them pick through.
        $this->service->runAutoDrafts();
        $session->set('flash', 'Pick made.');

        return Response::redirect('/draft');
    }

    public function addToQueue(Request $request, Session $session): Response
    {
        [$draft, $team, $error] = $this->queueContext($request, $session);
        if ($error !== null) {
            return $error;
        }

        $playerId = trim((string) $request->input('player_id', ''));
        if ($playerId === '' || !$this->players->isDraftable($playerId)) {
            return $this->renderRoom($request, $session, null, 'That player cannot be queued.', 400);
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
        [$draft, $team, $error] = $this->queueContext($request, $session);
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
        [$draft, $team, $error] = $this->queueContext($request, $session);
        if ($error !== null) {
            return $error;
        }

        $submitted = $request->post['player_ids'] ?? [];
        $submitted = is_array($submitted) ? $submitted : [];

        $clean = [];
        foreach ($submitted as $value) {
            $playerId = trim((string) $value);
            if ($playerId === '' || !$this->players->isDraftable($playerId)) {
                return $this->renderRoom($request, $session, null, 'That queue contains a player who cannot be drafted.', 400);
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
    private function queueContext(Request $request, Session $session): array
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        $draft = $this->drafts->find($leagueId, $seasonId);
        if ($draft === null || !in_array($draft['state'], self::QUEUE_OPEN_STATES, true)) {
            return [null, null, $this->renderRoom($request, $session, null, 'The queue is not open right now.', 409)];
        }

        $team = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));
        if ($team === null) {
            return [null, null, $this->renderRoom($request, $session, null, 'You do not manage a team in this draft.', 403)];
        }

        return [$draft, $team, null];
    }

    private function renderRoom(Request $request, Session $session, ?string $flash, ?string $error, int $status = 200): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $draft = $this->drafts->find($leagueId, $seasonId);
        $myTeam = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));

        // The Manager's position/name filter on the available pool (echoed back
        // to the view so the controls stay set across the room's auto-refresh).
        $filterPos = strtoupper(trim((string) ($request->query['pos'] ?? '')));
        if (!in_array($filterPos, self::FILTER_POSITIONS, true)) {
            $filterPos = '';
        }
        $filterQ = trim((string) ($request->query['q'] ?? ''));

        $board = [];
        $available = [];
        $myQueue = [];
        $onClockTeamId = null;
        $myTurn = false;
        $secondsLeft = null;
        $myRosterCounts = [];

        if ($draft !== null && in_array($draft['state'], ['live', 'paused', 'complete', 'aborted'], true)) {
            $board = $this->picks->board((int) $draft['id']);
        }

        if ($draft !== null && in_array($draft['state'], self::QUEUE_OPEN_STATES, true)) {
            $available = $this->players->availableForDraft(
                (int) $draft['id'],
                $filterQ !== '' ? $filterQ : null,
                $filterPos !== '' ? $filterPos : null,
            );
            if ($myTeam !== null) {
                $myQueue = $this->queues->queued((int) $draft['id'], (int) $myTeam['id']);
                $myRosterCounts = $this->picks->rosterPositionCounts((int) $draft['id'], (int) $myTeam['id']);
            }
        }

        $onClockName = null;
        $nextUpName = null;
        $myNextOverall = null;
        $myNextRound = null;
        $picksUntilMyTurn = null;

        if ($draft !== null && $draft['state'] === 'live' && $draft['current_pick_no'] !== null) {
            $currentNo = (int) $draft['current_pick_no'];

            // Index the board by overall pick so the clock, the next team up, and
            // the Manager's own next pick are one lookup each.
            $byOverall = [];
            foreach ($board as $row) {
                $byOverall[(int) $row['overall_pick']] = $row;
            }

            $current = $byOverall[$currentNo] ?? null;
            $onClockTeamId = $current !== null ? (int) $current['team_id'] : null;
            $onClockName = $current !== null ? (string) $current['team_name'] : null;
            $myTurn = $myTeam !== null && $onClockTeamId === (int) $myTeam['id'];

            $next = $byOverall[$currentNo + 1] ?? null;
            $nextUpName = $next !== null ? (string) $next['team_name'] : null;

            // The Manager's next unmade pick at or after the clock, and how many
            // picks away it is (0 = on the clock now).
            if ($myTeam !== null) {
                for ($overall = $currentNo; isset($byOverall[$overall]); $overall++) {
                    $slot = $byOverall[$overall];
                    if ((int) $slot['team_id'] === (int) $myTeam['id'] && $slot['player_id'] === null) {
                        $myNextOverall = $overall;
                        $myNextRound = (int) $slot['round'];
                        $picksUntilMyTurn = $overall - $currentNo;
                        break;
                    }
                }
            }

            if ($draft['current_deadline'] !== null) {
                $secondsLeft = max(0, strtotime((string) $draft['current_deadline']) - time());
            }
        }

        // Target roster shape, so a Manager can see the slots they still need to
        // fill (e.g. "K 0/1, DEF 0/1") while drafting.
        $settings = $this->settings->all($leagueId, $seasonId);
        $rosterShape = $this->rosterShape($settings);

        $isCommissioner = $session->get('role') === 'commissioner';
        $order = $draft !== null && $isCommissioner ? $this->drafts->order((int) $draft['id']) : [];

        // A Commissioner correcting a specific already-made pick (?fix=<overall>):
        // the available pool's action becomes "assign to pick #N" and a banner
        // names the pick being fixed. Only a made pick during a live/paused draft
        // is fixable this way (the correct-pick endpoint enforces the rest).
        $fixOverall = null;
        $fixCurrentName = null;
        if (
            $isCommissioner
            && $draft !== null
            && in_array($draft['state'], ['live', 'paused'], true)
            && ctype_digit((string) ($request->query['fix'] ?? ''))
        ) {
            $candidate = (int) $request->query['fix'];
            foreach ($board as $row) {
                if ((int) $row['overall_pick'] === $candidate && $row['player_id'] !== null) {
                    $fixOverall = $candidate;
                    $fixCurrentName = (string) $row['player_name'];
                    break;
                }
            }
        }

        return Response::html(
            $this->view->page('draft_room', 'Draft room', [
                'draft' => $draft,
                'board' => $board,
                'available' => $available,
                'myQueue' => $myQueue,
                'myTeam' => $myTeam,
                'onClockTeamId' => $onClockTeamId,
                'onClockName' => $onClockName,
                'nextUpName' => $nextUpName,
                'myNextOverall' => $myNextOverall,
                'myNextRound' => $myNextRound,
                'picksUntilMyTurn' => $picksUntilMyTurn,
                'autopickOnExpiry' => $draft !== null && (int) ($draft['autopick_on_expiry'] ?? 0) === 1,
                'myTurn' => $myTurn,
                'secondsLeft' => $secondsLeft,
                'myRosterCounts' => $myRosterCounts,
                'rosterShape' => $rosterShape,
                'filterPositions' => self::FILTER_POSITIONS,
                'filterPos' => $filterPos,
                'filterQ' => $filterQ,
                'isCommissioner' => $isCommissioner,
                'order' => $order,
                'fixOverall' => $fixOverall,
                'fixCurrentName' => $fixCurrentName,
                'flash' => $flash,
                'error' => $error,
            ], '', '', 'layout_app'),
            $status,
        );
    }

    /**
     * The starter slots the roster shape asks for, per position, for the
     * draft-room "still needed" summary. FLEX and bench are shown separately as a
     * flexible pool since any skill position can fill them.
     *
     * @param array<string,string> $settings
     * @return array{QB:int,RB:int,WR:int,TE:int,K:int,DEF:int,FLEX:int,BENCH:int}
     */
    private function rosterShape(array $settings): array
    {
        $slot = static fn (string $key): int => (int) ($settings['roster.' . $key] ?? 0);

        return [
            'QB' => $slot('qb'),
            'RB' => $slot('rb'),
            'WR' => $slot('wr'),
            'TE' => $slot('te'),
            'K' => $slot('k'),
            'DEF' => $slot('def'),
            'FLEX' => $slot('flex'),
            'BENCH' => $slot('bench'),
        ];
    }
}
