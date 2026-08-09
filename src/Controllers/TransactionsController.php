<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Session;
use FFB\Http\Response;
use FFB\LeagueRepository;
use FFB\TransactionRepository;
use FFB\Transactions\TransactionException;
use FFB\Transactions\TransactionService;
use FFB\View;

/**
 * The read-only, league-wide activity feed: every Roster-changing Transaction
 * (Adds, Drops, accepted Trades, Commissioner edits) in plain English, newest
 * first. When viewed by the Commissioner the page also offers a reverse action
 * on each Transaction (wired in a later slice).
 */
final class TransactionsController
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly TransactionRepository $ledger,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        $flash = $session->get('flash');
        $session->remove('flash');

        return $this->render($session, null, is_string($flash) ? $flash : null, 200);
    }

    /**
     * Commissioner-only: reverse an applied Transaction. On a conflict the
     * reversal is refused and the feed is re-rendered with the reason.
     */
    public function reverse(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $txnId = (int) $request->input('transaction_id', '0');

        try {
            $this->transactions->reverseTransaction($leagueId, $seasonId, $txnId, (int) $session->get('user_id'));
        } catch (TransactionException $e) {
            return $this->render($session, $e->getMessage(), null, $e->status);
        }

        $session->set('flash', 'Transaction reversed.');

        return Response::redirect('/transactions');
    }

    private function render(Session $session, ?string $error, ?string $flash, int $status): Response
    {
        $seasonId = $this->leagues->currentSeasonId();

        return Response::html(
            $this->view->page('transactions', 'Activity', [
                'feed' => $this->ledger->feed($seasonId),
                'isCommissioner' => $session->get('role') === 'commissioner',
                'flash' => $flash,
                'error' => $error,
            ], '', '', 'layout_app'),
            $status,
        );
    }
}
