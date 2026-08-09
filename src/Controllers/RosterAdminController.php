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
 * The Commissioner's manual roster-edit tool: move a Player between Teams, drop
 * one to the free-agent pool, or add a free agent to a Team, bypassing the
 * normal Add/Drop and Trade rules. Every edit is recorded as a reversible
 * commish_edit Transaction.
 */
final class RosterAdminController
{
    public function __construct(
        private readonly TransactionService $transactions,
        private readonly RosterRepository $rosters,
        private readonly PlayerRepository $players,
        private readonly TeamRepository $teams,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        return $this->render($session, null, 200);
    }

    public function edit(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        $playerId = trim((string) $request->input('player_id', ''));
        $toTeamRaw = trim((string) $request->input('to_team_id', ''));
        $toTeamId = $toTeamRaw === '' ? null : (int) $toTeamRaw;

        try {
            $this->transactions->commishSetPlayerTeam(
                $leagueId, $seasonId, $playerId, $toTeamId, (int) $session->get('user_id'),
            );
        } catch (TransactionException $e) {
            return $this->render($session, $e->getMessage(), $e->status);
        }

        $session->set('flash', 'Roster updated.');

        return Response::redirect('/admin/roster-edit');
    }

    private function render(Session $session, ?string $error, int $status): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        $names = $this->teams->namesForSeason($leagueId, $seasonId);
        $rostersByTeam = $this->rosters->byTeam($seasonId);
        $teams = [];
        foreach ($names as $id => $name) {
            $teams[] = ['id' => $id, 'name' => $name, 'roster' => $rostersByTeam[$id] ?? []];
        }

        $flash = $session->get('flash');
        $session->remove('flash');

        return Response::html(
            $this->view->page('roster_edit', 'Roster Edit', [
                'teams' => $teams,
                'freeAgents' => $this->players->availableForSeason($seasonId, null, null, 100),
                'error' => $error,
                'flash' => $error === null && is_string($flash) ? $flash : null,
            ], '', '', 'layout_app'),
            $status,
        );
    }
}
