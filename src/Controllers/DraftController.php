<?php

declare(strict_types=1);

namespace FFB\Controllers;

use FFB\DraftRepository;
use FFB\Http\Request;
use FFB\Http\Response;
use FFB\Http\Session;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
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
        private readonly LeagueSettingsRepository $settings,
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

    private function renderSetup(?string $flash, ?string $error, int $status = 200): Response
    {
        $leagueId = $this->leagues->currentLeagueId();
        $seasonId = $this->leagues->currentSeasonId();

        return Response::html(
            $this->view->page('draft_setup', 'Draft setup', [
                'draft' => $this->drafts->find($leagueId, $seasonId),
                'settings' => $this->settings->all($leagueId, $seasonId),
                'flash' => $flash,
                'error' => $error,
            ]),
            $status,
        );
    }
}
