<?php

declare(strict_types=1);

namespace FFB\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\View;

/**
 * Commissioner "Season Control": the weekly and per-season knobs that the
 * scoring crons read, without touching the database by hand. Starting a week
 * sets the current week and that week's lineup-lock (kickoff) time; the scoring
 * and roster forms edit the league_settings the engine reads. Successful POSTs
 * redirect back with a flash (post/redirect/get).
 */
final class SeasonController
{
    /** The lineup-lock time is entered in the league's local timezone. */
    private const LEAGUE_TZ = 'America/New_York';

    public function __construct(
        private readonly LeagueSettingsRepository $settings,
        private readonly LeagueRepository $leagues,
        private readonly View $view,
    ) {
    }

    public function index(Request $request, Session $session): Response
    {
        $flash = $session->get('flash');
        $session->remove('flash');

        return $this->render(is_string($flash) ? $flash : null, null);
    }

    public function startWeek(Request $request, Session $session): Response
    {
        $week = (int) $request->input('week', '0');
        $year = (int) $request->input('season_year', '0');
        $kickoff = trim((string) $request->input('kickoff', ''));

        if ($week < 1 || $week > 25) {
            return $this->render(null, 'Week must be between 1 and 25.', 400);
        }
        if ($year < 2000 || $year > 2100) {
            return $this->render(null, 'Enter a valid season year.', 400);
        }
        $iso = $this->toIso($kickoff);
        if ($iso === null) {
            return $this->render(null, 'Enter a valid lineup-lock date and time.', 400);
        }

        $this->settings->setMany($this->leagues->currentLeagueId(), $this->leagues->currentSeasonId(), [
            'schedule.season_year' => (string) $year,
            'schedule.current_week' => (string) $week,
            'schedule.week_' . $week . '_kickoff' => $iso,
        ]);

        $session->set('flash', "Week {$week} is now current; lineups lock at the time you set.");

        return Response::redirect('/admin/season');
    }

    public function saveTrades(Request $request, Session $session): Response
    {
        $raw = trim((string) $request->input('trade_deadline_week', ''));
        if ($raw !== '' && (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 25)) {
            return $this->render(null, 'Trade deadline must be a week between 1 and 25, or blank for none.', 400);
        }

        $this->settings->setMany($this->leagues->currentLeagueId(), $this->leagues->currentSeasonId(), [
            // Blank stores an empty value = no deadline (trading stays open).
            'schedule.trade_deadline_week' => $raw,
        ]);
        $session->set('flash', $raw === '' ? 'Trade deadline cleared.' : "Trades close after week {$raw}.");

        return Response::redirect('/admin/season');
    }

    public function savePlayoffs(Request $request, Session $session): Response
    {
        $raw = trim((string) $request->input('team_count', ''));
        if ($raw === '' || !ctype_digit($raw) || (int) $raw < 2) {
            return $this->render(null, 'Playoff teams must be a whole number, 2 or more.', 400);
        }

        $this->settings->setMany($this->leagues->currentLeagueId(), $this->leagues->currentSeasonId(), [
            'playoffs.team_count' => (string) (int) $raw,
        ]);
        $session->set('flash', "{$raw} teams will make the playoffs.");

        return Response::redirect('/admin/season');
    }

    public function saveScoring(Request $request, Session $session): Response
    {
        return $this->saveGroup($request, $session, 'scoring', 'Scoring settings saved.');
    }

    public function saveRoster(Request $request, Session $session): Response
    {
        return $this->saveGroup($request, $session, 'roster', 'Roster settings saved.');
    }

    /**
     * Persist one prefixed group of settings from a `<group>[key] = value` form.
     * Values must be numeric; roster values are coerced to non-negative integers.
     */
    private function saveGroup(Request $request, Session $session, string $group, string $success): Response
    {
        $submitted = $request->post[$group] ?? [];
        if (!is_array($submitted) || $submitted === []) {
            return $this->render(null, 'Nothing to save.', 400);
        }

        $updates = [];
        foreach ($submitted as $key => $value) {
            $value = trim((string) $value);
            if (!is_numeric($value)) {
                return $this->render(null, "“{$key}” must be a number.", 400);
            }
            $updates[$group . '.' . $key] = $group === 'roster'
                ? (string) max(0, (int) $value)
                : $value;
        }

        $this->settings->setMany($this->leagues->currentLeagueId(), $this->leagues->currentSeasonId(), $updates);
        $session->set('flash', $success);

        return Response::redirect('/admin/season');
    }

    /**
     * Convert a datetime-local string (e.g. "2026-09-10T20:20"), interpreted in
     * the league timezone, to an ISO 8601 string with offset. Null when invalid.
     */
    private function toIso(string $local): ?string
    {
        if ($local === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($local, new DateTimeZone(self::LEAGUE_TZ));
        } catch (\Exception) {
            return null;
        }

        return $dt->format('c');
    }

    private function render(?string $flash, ?string $error, int $status = 200): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $all = $this->settings->all($leagueId, $seasonId);

        $currentWeek = (int) ($all['schedule.current_week'] ?? 0);
        $scoring = [];
        $roster = [];
        foreach ($all as $key => $value) {
            if (str_starts_with($key, 'scoring.')) {
                $scoring[substr($key, strlen('scoring.'))] = $value;
            } elseif (str_starts_with($key, 'roster.')) {
                $roster[substr($key, strlen('roster.'))] = $value;
            }
        }
        ksort($scoring);
        ksort($roster);

        return Response::html(
            $this->view->page('season', 'Season control', [
                'currentWeek' => $currentWeek,
                'nextWeek' => max(1, $currentWeek + 1),
                'seasonYear' => (int) ($all['schedule.season_year'] ?? (int) date('Y')),
                'kickoffPrefill' => $this->prefillKickoff(),
                'tradeDeadlineWeek' => (string) ($all['schedule.trade_deadline_week'] ?? ''),
                'playoffTeamCount' => (string) ($all['playoffs.team_count'] ?? '4'),
                'scoring' => $scoring,
                'roster' => $roster,
                'flash' => $flash,
                'error' => $error,
            ], '', '', 'layout_app'),
            $status,
        );
    }

    /**
     * A sensible default lineup-lock: the coming Thursday at 8:20pm league time,
     * formatted for a datetime-local input.
     */
    private function prefillKickoff(): string
    {
        $dt = new DateTimeImmutable('next thursday 20:20', new DateTimeZone(self::LEAGUE_TZ));

        return $dt->format('Y-m-d\TH:i');
    }
}
