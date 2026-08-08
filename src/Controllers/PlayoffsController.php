<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\Playoffs\PlayoffException;
use FFB\Playoffs\PlayoffService;

/**
 * The Playoffs: a read-only bracket view for everyone, plus the Commissioner
 * actions that drive the bracket (create, advance, correct, reset). Manager-
 * facing rendering lands in a later slice; for now the Commissioner actions
 * redirect back to Season Control and refuse illegal requests with a clear
 * status + message.
 */
final class PlayoffsController
{
    public function __construct(
        private readonly PlayoffService $playoffs,
        private readonly LeagueRepository $leagues,
    ) {
    }

    public function create(Request $request, Session $session): Response
    {
        return $this->run($session, function (int $leagueId, int $seasonId): void {
            $this->playoffs->create($leagueId, $seasonId);
        }, 'The playoff bracket is set.');
    }

    /**
     * @param callable(int,int):void $action
     */
    private function run(Session $session, callable $action, string $success): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        try {
            $action($leagueId, $seasonId);
        } catch (PlayoffException $e) {
            return Response::html($e->getMessage(), $e->status);
        }

        $session->set('flash', $success);

        return Response::redirect('/admin/season');
    }
}
