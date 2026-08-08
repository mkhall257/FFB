<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\DraftPickRepository;
use FFB\DraftRepository;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
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
        private readonly LeagueSettingsRepository $settings,
        private readonly TeamRepository $teams,
        private readonly LeagueRepository $leagues,
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
            ]),
            $status,
        );
    }
}
