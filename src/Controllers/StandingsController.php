<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\StandingsService;
use FFB\TeamRepository;
use FFB\View;

/**
 * The league Standings page: Teams ranked by record then points, the seed order
 * for the (future) Playoffs.
 */
final class StandingsController
{
    public function __construct(
        private readonly StandingsService $standings,
        private readonly TeamRepository $teams,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        return Response::html($this->view->page('standings', 'Standings', [
            'rows' => $this->standings->compute($seasonId),
            'names' => $this->teams->namesForSeason($leagueId, $seasonId),
        ], 'standings', 'standings', 'layout_app'));
    }
}
