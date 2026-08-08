<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\Lineup\LineupException;
use FFB\Lineup\LineupService;
use FFB\LineupRepository;
use FFB\RosterRepository;
use FFB\TeamRepository;
use FFB\View;

/**
 * A Manager's weekly Lineup page. On view, the Team's Lineup is ensured (carried
 * forward or Week-1 auto-filled) so there is always something to edit. On save,
 * the submitted slots are validated and persisted unless the week is locked. A
 * Manager only ever edits their own Team — the Team is resolved from the session.
 */
final class LineupController
{
    public function __construct(
        private readonly LineupService $lineups,
        private readonly LineupRepository $lineupRepo,
        private readonly RosterRepository $rosters,
        private readonly TeamRepository $teams,
        private readonly LeagueSettingsRepository $settings,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        return $this->render($session, null, null);
    }

    public function save(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $team = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));
        if ($team === null) {
            return $this->render($session, null, 'You do not manage a team.', 403);
        }

        $week = $this->currentWeek($leagueId, $seasonId);
        $plan = $this->lineups->slotPlan($this->settings->all($leagueId, $seasonId));
        $submitted = $request->post['players'] ?? [];
        $submitted = is_array($submitted) ? $submitted : [];

        $assignments = [];
        foreach ($plan as $s) {
            $key = $s['roster_slot'] . ':' . $s['slot_index'];
            $pid = trim((string) ($submitted[$key] ?? ''));
            $assignments[] = [
                'roster_slot' => $s['roster_slot'],
                'slot_index' => $s['slot_index'],
                'player_id' => $pid === '' ? null : $pid,
            ];
        }

        try {
            $this->lineups->saveLineup($leagueId, $seasonId, $week, (int) $team['id'], $assignments);
        } catch (LineupException $e) {
            return $this->render($session, null, $e->getMessage(), $e->status);
        }

        $session->set('flash', 'Lineup saved.');

        return Response::redirect('/lineup');
    }

    private function render(Session $session, ?string $flashOverride, ?string $error, int $status = 200): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $team = $this->teams->findByUser($leagueId, $seasonId, (int) $session->get('user_id'));

        if ($team === null) {
            return Response::html(
                $this->view->page('lineup', 'My Lineup', [
                    'hasTeam' => false, 'week' => 0, 'slots' => [], 'roster' => [],
                    'locked' => false, 'error' => 'You do not manage a team.', 'flash' => null,
                ]),
                403,
            );
        }

        $teamId = (int) $team['id'];
        $week = $this->currentWeek($leagueId, $seasonId);
        $this->lineups->ensureLineup($leagueId, $seasonId, $week, $teamId);

        // Merge the slot plan with the current assignments for display.
        $current = [];
        foreach ($this->lineupRepo->forTeamWeek($seasonId, $week, $teamId) as $row) {
            $current[$row['roster_slot'] . ':' . $row['slot_index']] = $row['player_id'];
        }
        $slots = [];
        foreach ($this->lineups->slotPlan($this->settings->all($leagueId, $seasonId)) as $s) {
            $slots[] = $s + ['player_id' => $current[$s['roster_slot'] . ':' . $s['slot_index']] ?? null];
        }

        $flash = $session->get('flash');
        $session->remove('flash');

        return Response::html(
            $this->view->page('lineup', 'My Lineup', [
                'hasTeam' => true,
                'week' => $week,
                'slots' => $slots,
                'roster' => $this->rosters->byTeam($seasonId)[$teamId] ?? [],
                'locked' => $this->lineups->isLocked($leagueId, $seasonId, $week),
                'error' => $error,
                'flash' => $flashOverride ?? (is_string($flash) ? $flash : null),
            ]),
            $status,
        );
    }

    private function currentWeek(int $leagueId, int $seasonId): int
    {
        $all = $this->settings->all($leagueId, $seasonId);

        return max(1, (int) ($all['schedule.current_week'] ?? 1));
    }
}
