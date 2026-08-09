<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\Draft\DraftPickException;
use FFB\Draft\DraftService;
use FFB\DraftPickRepository;
use FFB\DraftRepository;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\MatchupRepository;
use FFB\LeagueSettingsRepository;
use FFB\PlayerRepository;
use FFB\RosterRepository;
use FFB\TeamRepository;
use FFB\View;
use PDO;

/**
 * Commissioner-only Draft setup: configure the pick timer, the expiry-triggered
 * Auto-pick toggle, an optional display-only draft date, and the roster shape
 * (see CONTEXT.md, ADR-0007). Configuring lazily creates the Season's Draft in
 * the Setup state. Order-setting and going Live come in later slices.
 */
final class DraftController
{
    private const MIN_PICK_SECONDS = 15;
    private const MAX_PICK_SECONDS = 600;
    private const MIN_TEAMS = 2;

    /** Draft-config form field => league_settings key for the roster shape. */
    private const ROSTER_FIELDS = [
        'roster_qb' => 'roster.qb',
        'roster_rb' => 'roster.rb',
        'roster_wr' => 'roster.wr',
        'roster_te' => 'roster.te',
        'roster_flex' => 'roster.flex',
        'roster_k' => 'roster.k',
        'roster_def' => 'roster.def',
        'roster_bench' => 'roster.bench',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly DraftRepository $drafts,
        private readonly DraftPickRepository $picks,
        private readonly DraftService $service,
        private readonly LeagueSettingsRepository $settings,
        private readonly TeamRepository $teams,
        private readonly PlayerRepository $players,
        private readonly RosterRepository $rosters,
        private readonly LeagueRepository $leagues,
        private readonly MatchupRepository $matchups,
        private readonly View $view,
    ) {
    }

    public function setup(Request $request, Session $session): Response
    {
        $flash = $session->get('flash');
        $session->remove('flash');

        return $this->renderSetup(is_string($flash) ? $flash : null, null);
    }

    public function configure(Request $request, Session $session): Response
    {
        $pickSeconds = (int) $request->input('pick_seconds', '0');
        if ($pickSeconds < self::MIN_PICK_SECONDS || $pickSeconds > self::MAX_PICK_SECONDS) {
            return $this->renderSetup(
                null,
                'Pick timer must be between 15 and 600 seconds.',
                400,
            );
        }

        $autopick = $request->input('autopick_on_expiry', '1') === '1';

        $scheduledRaw = trim((string) $request->input('scheduled_at', ''));
        $scheduledAt = null;
        if ($scheduledRaw !== '') {
            $timestamp = strtotime($scheduledRaw);
            if ($timestamp === false) {
                return $this->renderSetup(null, 'That draft date/time could not be read.', 400);
            }
            $scheduledAt = date('Y-m-d H:i:s', $timestamp);
        }

        $rosterUpdates = [];
        foreach (self::ROSTER_FIELDS as $field => $settingKey) {
            $raw = $request->input($field, null);
            if ($raw === null) {
                continue;
            }
            if (!ctype_digit($raw)) {
                return $this->renderSetup(null, 'Roster slot counts must be whole numbers.', 400);
            }
            $rosterUpdates[$settingKey] = (string) (int) $raw;
        }

        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        $this->pdo->beginTransaction();
        try {
            $draft = $this->drafts->currentOrCreate($leagueId, $seasonId);
            $this->drafts->updateConfig((int) $draft['id'], $pickSeconds, $autopick, $scheduledAt);
            if ($rosterUpdates !== []) {
                $this->settings->setMany($leagueId, $seasonId, $rosterUpdates);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $session->set('flash', 'Draft settings saved.');

        return Response::redirect('/admin/draft');
    }

    public function randomizeOrder(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $draft = $this->drafts->currentOrCreate($leagueId, $seasonId);

        if ($draft['state'] !== 'setup') {
            return $this->renderSetup(null, 'The draft order is locked once the Draft is finalized.', 409);
        }

        $ids = $this->teams->idsForSeason($leagueId, $seasonId);
        if (count($ids) < self::MIN_TEAMS) {
            return $this->renderSetup(null, 'Add at least two teams before setting the draft order.', 400);
        }

        shuffle($ids);
        $this->drafts->setOrder((int) $draft['id'], $ids);
        $session->set('flash', 'Draft order randomized.');

        return Response::redirect('/admin/draft');
    }

    public function reorder(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $draft = $this->drafts->currentOrCreate($leagueId, $seasonId);

        if ($draft['state'] !== 'setup') {
            return $this->renderSetup(null, 'The draft order is locked once the Draft is finalized.', 409);
        }

        $submitted = $request->post['team_ids'] ?? [];
        $submitted = is_array($submitted) ? array_map(intval(...), $submitted) : [];
        $seasonIds = $this->teams->idsForSeason($leagueId, $seasonId);

        if (!$this->isPermutation($submitted, $seasonIds)) {
            return $this->renderSetup(null, 'The order must list every team exactly once.', 400);
        }

        $this->drafts->setOrder((int) $draft['id'], $submitted);
        $session->set('flash', 'Draft order saved.');

        return Response::redirect('/admin/draft');
    }

    public function finalize(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $draft = $this->drafts->currentOrCreate($leagueId, $seasonId);

        if ($draft['state'] !== 'setup') {
            return $this->renderSetup(null, 'The Draft has already been finalized.', 409);
        }

        $order = $this->drafts->orderTeamIds((int) $draft['id']);
        $seasonIds = $this->teams->idsForSeason($leagueId, $seasonId);

        if ($order === [] || count($order) !== count($seasonIds)) {
            return $this->renderSetup(
                null,
                'Set the draft order for every team before finalizing.',
                400,
            );
        }

        $this->drafts->setState((int) $draft['id'], 'ready');
        $session->set('flash', 'Draft finalized. Managers can now see the order and build their queues.');

        return Response::redirect('/admin/draft');
    }

    public function start(Request $request, Session $session): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $draft = $this->drafts->currentOrCreate($leagueId, $seasonId);

        if ($draft['state'] !== 'ready') {
            return $this->renderSetup(null, 'Finalize the draft order before starting it.', 409);
        }

        $order = $this->drafts->orderTeamIds((int) $draft['id']);
        $settings = $this->settings->all($leagueId, $seasonId);
        $rounds = $this->rounds($settings);

        if ($rounds < 1) {
            return $this->renderSetup(null, 'Set a roster shape before starting the draft.', 400);
        }

        $this->pdo->beginTransaction();
        try {
            $this->picks->generateBoard((int) $draft['id'], $order, $rounds);
            $this->drafts->start((int) $draft['id'], (int) $draft['pick_seconds']);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $session->set('flash', 'The draft is live!');

        return Response::redirect('/admin/draft');
    }

    public function pause(Request $request, Session $session): Response
    {
        $draft = $this->currentDraft();
        if ($draft === null || $draft['state'] !== 'live') {
            return Response::html('Only a live draft can be paused.', 409);
        }

        $remaining = $draft['current_deadline'] !== null
            ? max(0, strtotime((string) $draft['current_deadline']) - time())
            : (int) $draft['pick_seconds'];
        $this->drafts->pause((int) $draft['id'], $remaining);
        $session->set('flash', 'Draft paused.');

        return Response::redirect('/draft');
    }

    public function resume(Request $request, Session $session): Response
    {
        $draft = $this->currentDraft();
        if ($draft === null || $draft['state'] !== 'paused') {
            return Response::html('Only a paused draft can be resumed.', 409);
        }

        $this->drafts->resume((int) $draft['id']);
        $session->set('flash', 'Draft resumed.');

        return Response::redirect('/draft');
    }

    public function addTime(Request $request, Session $session): Response
    {
        $draft = $this->currentDraft();
        if ($draft === null || !in_array($draft['state'], ['live', 'paused'], true)) {
            return Response::html('The clock is not running.', 409);
        }

        $seconds = (int) $request->input('seconds', '0');
        if ($seconds <= 0) {
            return Response::html('Enter a positive number of seconds.', 400);
        }

        $this->drafts->addTime((int) $draft['id'], $seconds, $draft['state'] === 'paused');
        $session->set('flash', "Added {$seconds}s to the clock.");

        return Response::redirect('/draft');
    }

    public function pickOnBehalf(Request $request, Session $session): Response
    {
        $draft = $this->currentDraft();
        if ($draft === null || $draft['state'] !== 'live') {
            return Response::html('The draft is not live.', 409);
        }

        $current = $this->picks->findByOverall((int) $draft['id'], (int) $draft['current_pick_no']);
        if ($current === null) {
            return Response::html('No pick is on the clock.', 409);
        }

        try {
            $this->service->pick($draft, (int) $current['team_id'], (string) $request->input('player_id', ''), 'commissioner');
        } catch (DraftPickException $e) {
            return Response::html($e->getMessage(), $e->status);
        }
        $this->service->runAutoDrafts();
        $session->set('flash', 'Pick made for the team on the clock.');

        return Response::redirect('/draft');
    }

    public function toggleAutoDraft(Request $request, Session $session): Response
    {
        $draft = $this->currentDraft();
        if ($draft === null || !in_array($draft['state'], ['live', 'paused'], true)) {
            return Response::html('Auto-draft can only be changed during a live draft.', 409);
        }

        $teamId = (int) $request->input('team_id', '0');
        $team = $this->teams->find(
            $this->leagues->currentLeagueId(),
            $this->leagues->currentSeasonId(),
            $teamId,
        );
        if ($team === null) {
            return Response::html('Unknown team.', 400);
        }

        $enabled = $request->input('enabled', '0') === '1';
        $this->drafts->setAutoDraft((int) $draft['id'], $teamId, $enabled);
        if ($enabled) {
            // May be this team's turn right now — let it pick immediately.
            $this->service->runAutoDrafts();
        }
        $session->set('flash', $enabled ? 'Auto-draft turned on.' : 'Auto-draft turned off.');

        return Response::redirect('/draft');
    }

    public function correctPick(Request $request, Session $session): Response
    {
        $draft = $this->currentDraft();
        if ($draft === null) {
            return Response::html('There is no draft.', 409);
        }

        $overall = (int) $request->input('overall_pick', '0');
        $pick = $this->picks->findByOverall((int) $draft['id'], $overall);
        if ($pick === null || $pick['player_id'] === null) {
            return Response::html('That pick has not been made yet.', 400);
        }

        $playerId = trim((string) $request->input('player_id', ''));
        if ($playerId === '' || !$this->players->isDraftable($playerId)) {
            return Response::html('Choose a valid player.', 400);
        }
        if ($this->picks->isPlayerTakenByOther((int) $draft['id'], $playerId, (int) $pick['id'])) {
            return Response::html('That player is already on another pick.', 409);
        }

        $this->picks->assignPlayer((int) $pick['id'], $playerId, 'commissioner');
        $session->set('flash', 'Pick corrected.');

        return Response::redirect('/draft');
    }

    public function undoLast(Request $request, Session $session): Response
    {
        $draft = $this->currentDraft();
        if ($draft === null) {
            return Response::html('There is no draft.', 409);
        }

        $last = $this->picks->lastMadePick((int) $draft['id']);
        if ($last === null) {
            return Response::html('There are no picks to undo.', 409);
        }

        $this->pdo->beginTransaction();
        try {
            $this->picks->clearPick((int) $last['id']);
            $this->drafts->revertTo((int) $draft['id'], (int) $last['overall_pick'], (int) $draft['pick_seconds']);
            // Reopening a completed Draft invalidates the materialized rosters
            // and the Schedule generated from them.
            $this->rosters->clearForSeason($this->leagues->currentSeasonId());
            $this->matchups->clearForSeason($this->leagues->currentSeasonId());
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $session->set('flash', 'Last pick undone.');

        return Response::redirect('/draft');
    }

    public function reset(Request $request, Session $session): Response
    {
        $draft = $this->currentDraft();
        if ($draft === null || $draft['state'] === 'setup') {
            return Response::html('There is nothing to reset.', 409);
        }

        $this->pdo->beginTransaction();
        try {
            $this->picks->clearBoard((int) $draft['id']);
            $this->drafts->resetToSetup((int) $draft['id']);
            $this->rosters->clearForSeason($this->leagues->currentSeasonId());
            $this->matchups->clearForSeason($this->leagues->currentSeasonId());
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $session->set('flash', 'Draft reset to setup.');

        return Response::redirect('/admin/draft');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function currentDraft(): ?array
    {
        return $this->drafts->find(
            $this->leagues->currentLeagueId(),
            $this->leagues->currentSeasonId(),
        );
    }

    /**
     * Total Draft rounds = starter slots + bench, from the roster shape.
     *
     * @param array<string,string> $settings
     */
    private function rounds(array $settings): int
    {
        $slot = static fn (string $key): int => (int) ($settings['roster.' . $key] ?? 0);

        return $slot('qb') + $slot('rb') + $slot('wr') + $slot('te')
            + $slot('flex') + $slot('k') + $slot('def') + $slot('bench');
    }

    /**
     * True when $candidate contains exactly the members of $expected, once each.
     *
     * @param list<int> $candidate
     * @param list<int> $expected
     */
    private function isPermutation(array $candidate, array $expected): bool
    {
        if (count($candidate) !== count($expected)) {
            return false;
        }
        if (count(array_unique($candidate)) !== count($candidate)) {
            return false;
        }

        sort($candidate);
        sort($expected);

        return $candidate === $expected;
    }

    private function renderSetup(?string $flash, ?string $error, int $status = 200): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();
        $draft = $this->drafts->find($leagueId, $seasonId);

        return Response::html(
            $this->view->page('draft_setup', 'Draft setup', [
                'draft' => $draft,
                'settings' => $this->settings->all($leagueId, $seasonId),
                'order' => $draft !== null ? $this->drafts->order((int) $draft['id']) : [],
                'teams' => $this->teams->listWithManagers($leagueId, $seasonId),
                'flash' => $flash,
                'error' => $error,
            ], '', '', 'layout_app'),
            $status,
        );
    }
}
