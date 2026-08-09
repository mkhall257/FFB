<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\DraftRepository;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\RosterRepository;
use FFB\TeamRepository;
use FFB\TransactionRepository;
use FFB\Transactions\TransactionException;
use FFB\Transactions\TransactionService;
use FFB\View;

/**
 * The Manager-facing Trade surface: incoming offers (accept/reject), outgoing
 * offers (cancel), and a propose-a-trade builder. A Manager only ever acts as
 * their own Team — resolved from the session.
 */
final class TradesController
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly TransactionRepository $ledger,
        private readonly RosterRepository $rosters,
        private readonly TeamRepository $teams,
        private readonly DraftRepository $drafts,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        return $this->render($session, null, 200);
    }

    public function propose(Request $request, Session $session): Response
    {
        [$leagueId, $seasonId, $team] = $this->context($session);
        if ($team === null) {
            return $this->render($session, 'You do not manage a team.', 403);
        }

        $offered = $this->ids($request->post['offered'] ?? []);
        $requested = $this->ids($request->post['requested'] ?? []);
        $targetTeamId = (int) $request->input('target_team_id', '0');

        try {
            $this->transactions->proposeTrade(
                $leagueId, $seasonId, (int) $team['id'], $targetTeamId,
                (int) $session->get('user_id'), $offered, $requested,
            );
        } catch (TransactionException $e) {
            return $this->render($session, $e->getMessage(), $e->status);
        }

        $session->set('flash', 'Trade proposed.');

        return Response::redirect('/trades');
    }

    public function accept(Request $request, Session $session): Response
    {
        return $this->act($session, 'accept', (int) $request->input('transaction_id', '0'));
    }

    public function reject(Request $request, Session $session): Response
    {
        return $this->act($session, 'reject', (int) $request->input('transaction_id', '0'));
    }

    public function cancel(Request $request, Session $session): Response
    {
        return $this->act($session, 'cancel', (int) $request->input('transaction_id', '0'));
    }

    private function act(Session $session, string $action, int $txnId): Response
    {
        [$leagueId, $seasonId, $team] = $this->context($session);
        if ($team === null) {
            return $this->render($session, 'You do not manage a team.', 403);
        }
        $teamId = (int) $team['id'];

        try {
            match ($action) {
                'accept' => $this->transactions->acceptTrade($leagueId, $seasonId, $txnId, $teamId, (int) $session->get('user_id')),
                'reject' => $this->transactions->rejectTrade($seasonId, $txnId, $teamId),
                'cancel' => $this->transactions->cancelTrade($seasonId, $txnId, $teamId),
            };
        } catch (TransactionException $e) {
            return $this->render($session, $e->getMessage(), $e->status);
        }

        $session->set('flash', 'Trade ' . $action . 'ed.');

        return Response::redirect('/trades');
    }

    private function render(Session $session, ?string $error, int $status): Response
    {
        [$leagueId, $seasonId, $team] = $this->context($session);
        $teamId = $team !== null ? (int) $team['id'] : 0;

        $open = $team !== null ? $this->ledger->openTradesForTeam($seasonId, $teamId) : [];
        $incoming = [];
        $outgoing = [];
        foreach ($open as $t) {
            if ((int) $t['accepted_by_team'] === $teamId) {
                $incoming[] = $t;
            } else {
                $outgoing[] = $t;
            }
        }

        // Trades open only once the Draft is complete and Rosters exist; mirror
        // the service's own gate (assertTransactionsOpen) so the page explains the
        // pre-draft state instead of rendering empty team headers under "You get".
        $draft = $this->drafts->find($leagueId, $seasonId);
        $draftComplete = $draft !== null && ($draft['state'] ?? null) === 'complete';

        $rostersByTeam = $this->rosters->byTeam($seasonId);
        $otherTeams = [];
        if ($team !== null) {
            foreach ($this->teams->namesForSeason($leagueId, $seasonId) as $id => $name) {
                // Skip yourself and any Team with no rostered players (nothing to get).
                if ($id !== $teamId && ($rostersByTeam[$id] ?? []) !== []) {
                    $otherTeams[] = ['id' => $id, 'name' => $name, 'roster' => $rostersByTeam[$id]];
                }
            }
        }

        $flash = $session->get('flash');
        $session->remove('flash');

        return Response::html(
            $this->view->page('trades', 'Trades', [
                'hasTeam' => $team !== null,
                'draftComplete' => $draftComplete,
                'incoming' => $incoming,
                'outgoing' => $outgoing,
                'myRoster' => $team !== null ? ($rostersByTeam[$teamId] ?? []) : [],
                'otherTeams' => $otherTeams,
                'error' => $error,
                'flash' => $error === null && is_string($flash) ? $flash : null,
            ], '', '', 'layout_app'),
            $status,
        );
    }

    /**
     * @return array{0:int,1:int,2:?array<string,mixed>}
     */
    private function context(Session $session): array
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $team = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));

        return [$leagueId, $seasonId, $team];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    private function ids(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_map(static fn ($v): string => (string) $v, $raw));
    }
}
