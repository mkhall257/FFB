<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Session;
use FFB\Http\Response;
use FFB\LeagueRepository;
use FFB\TransactionRepository;
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
        private readonly TransactionRepository $ledger,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        $seasonId = $this->leagues->currentSeasonId();

        $flash = $session->get('flash');
        $session->remove('flash');
        $error = $session->get('txn_error');
        $session->remove('txn_error');

        return Response::html(
            $this->view->page('transactions', 'Activity', [
                'feed' => $this->ledger->feed($seasonId),
                'isCommissioner' => $session->get('role') === 'commissioner',
                'flash' => is_string($flash) ? $flash : null,
                'error' => is_string($error) ? $error : null,
            ]),
        );
    }
}
