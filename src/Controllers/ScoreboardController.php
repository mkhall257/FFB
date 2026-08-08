<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\MatchupRepository;
use FFB\TeamRepository;
use FFB\View;

/**
 * The weekly Scoreboard: every Matchup for a week with its cached scores and a
 * state label (Scheduled / Live / Final). Per ADR-0005 the UI always labels
 * which state a score is in.
 */
final class ScoreboardController
{
    private const STATE_LABELS = ['scheduled' => 'Scheduled', 'live' => 'Live', 'final' => 'Final'];

    public function __construct(
        private readonly MatchupRepository $matchups,
        private readonly TeamRepository $teams,
        private readonly LeagueSettingsRepository $settings,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $all = $this->settings->all($leagueId, $seasonId);

        $week = (int) ($request->query['week'] ?? ($all['schedule.current_week'] ?? 1));
        $week = max(1, $week);

        return Response::html($this->view->page('scoreboard', "Scoreboard · Week {$week}", [
            'week' => $week,
            'matchups' => $this->matchups->forWeek($seasonId, $week),
            'names' => $this->teams->namesForSeason($leagueId, $seasonId),
            'stateLabels' => self::STATE_LABELS,
        ]));
    }
}
