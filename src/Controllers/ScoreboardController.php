<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\MatchupRepository;
use FFB\Scoring\MatchupDetailService;
use FFB\StandingsService;
use FFB\TeamRepository;
use FFB\View;

/**
 * The weekly Scoreboard: every Matchup for a week expanded to a per-starter
 * comparison with cached team totals and a state label (Scheduled / Live /
 * Final). Per ADR-0005 the UI always labels which state a score is in.
 */
final class ScoreboardController
{
    private const STATE_LABELS = ['scheduled' => 'Scheduled', 'live' => 'Live', 'final' => 'Final'];

    public function __construct(
        private readonly MatchupRepository $matchups,
        private readonly MatchupDetailService $detail,
        private readonly StandingsService $standings,
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

        $names = $this->teams->namesForSeason($leagueId, $seasonId);
        $records = [];
        foreach ($this->standings->compute($seasonId) as $r) {
            $tid = (int) $r['team_id'];
            $records[$tid] = (int) $r['wins'] . '-' . (int) $r['losses']
                . ((int) $r['ties'] > 0 ? '-' . (int) $r['ties'] : '');
        }

        $matchups = $this->detail->forWeek($leagueId, $seasonId, $week);
        foreach ($matchups as &$m) {
            foreach (['home', 'away'] as $side) {
                $tid = (int) $m[$side]['team_id'];
                $m[$side]['name'] = $names[$tid] ?? ('Team ' . $tid);
                $m[$side]['record'] = $records[$tid] ?? '';
            }
        }
        unset($m);

        return Response::html($this->view->page('scoreboard', "Scoreboard · Week {$week}", [
            'week' => $week,
            'matchups' => $matchups,
            'stateLabels' => self::STATE_LABELS,
        ], 'matchup', 'matchup', 'layout_app'));
    }
}
