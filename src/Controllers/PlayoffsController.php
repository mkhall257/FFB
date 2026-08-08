<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueClock;
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
        return $this->run($request, $session, function (int $leagueId, int $seasonId, ?string $kickoff): void {
            $this->playoffs->create($leagueId, $seasonId, $kickoff);
        }, 'The playoff bracket is set.');
    }

    public function advance(Request $request, Session $session): Response
    {
        return $this->run($request, $session, function (int $leagueId, int $seasonId, ?string $kickoff): void {
            $this->playoffs->advance($leagueId, $seasonId, $kickoff);
        }, 'The next playoff round is open.');
    }

    /**
     * @param callable(int,int,?string):void $action
     */
    private function run(Request $request, Session $session, callable $action, string $success): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        $rawKickoff = trim((string) $request->input('kickoff', ''));
        $kickoff = $rawKickoff === '' ? null : LeagueClock::toIso($rawKickoff);
        if ($rawKickoff !== '' && $kickoff === null) {
            return Response::html('Enter a valid lineup-lock date and time.', 400);
        }

        try {
            $action($leagueId, $seasonId, $kickoff);
        } catch (PlayoffException $e) {
            return Response::html($e->getMessage(), $e->status);
        }

        $session->set('flash', $success);

        return Response::redirect('/admin/season');
    }
}
