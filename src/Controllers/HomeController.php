<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\TeamRepository;
use FFB\TransactionRepository;
use FFB\View;

/**
 * The authenticated landing page shown after login. Surfaces the count of
 * pending Trade offers awaiting the Manager's response (the in-app badge).
 */
final class HomeController
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly TransactionRepository $ledger,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        $incoming = 0;
        $userId = $session->get('user_id');
        if ($userId !== null) {
            $leagueId = $this->leagues->currentLeagueId();
            $seasonId = $this->leagues->currentSeasonId();
            $team = $this->teams->findByUser($leagueId, $seasonId, (int) $userId);
            if ($team !== null) {
                $incoming = $this->ledger->incomingProposalCount($seasonId, (int) $team['id']);
            }
        }

        return Response::html($this->view->page('home', 'Home', [
            'displayName' => (string) $session->get('display_name', ''),
            'role' => (string) $session->get('role', ''),
            'incomingOffers' => $incoming,
        ]));
    }
}
