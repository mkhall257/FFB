<?php

declare(strict_types=1);

/**
 * CLI: advance the Draft on a timer, for a host cron. One tick does, in order:
 *
 *   1. Auto-start a pre-staged Draft whose scheduled date/time has arrived
 *      (a finalized/Ready Draft with a due scheduled_at goes Live).
 *   2. Resolve an expired pick's Auto-pick, if the timer ran out and expiry
 *      Auto-pick is on.
 *   3. Run any consecutive Auto-draft Teams that are now on the clock.
 *
 * All three are the same poll-driven operations a page load performs, so the
 * cron is a safety net: the Draft still auto-starts and keeps moving even when
 * nobody has the draft room open. Safe to run every minute — it no-ops unless
 * there is something to do.
 *
 * ICDSoft cron (php83.cli, from the project root), e.g. every minute:
 *   * * * * * /usr/local/bin/php83.cli /home/USER/ffb/bin/draft-tick.php >/dev/null 2>&1
 *
 * Usage (manual):
 *   php bin/draft-tick.php          # run one tick, print what it did
 */

use FFB\Database;
use FFB\Draft\AutoPickStrategy;
use FFB\Draft\DraftService;
use FFB\DraftPickRepository;
use FFB\DraftQueueRepository;
use FFB\DraftRepository;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\RosterRepository;
use FFB\Schedule\ScheduleGenerator;
use FFB\Schedule\ScheduleService;
use FFB\TeamRepository;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

// Mirror the subset of Kernel's wiring the DraftService needs.
$leagues = new LeagueRepository($pdo);
$teams = new TeamRepository($pdo);
$players = new PlayerRepository($pdo);
$settings = new LeagueSettingsRepository($pdo);
$drafts = new DraftRepository($pdo);
$draftPicks = new DraftPickRepository($pdo);
$draftQueues = new DraftQueueRepository($pdo);
$rosters = new RosterRepository($pdo);
$matchups = new MatchupRepository($pdo);

$schedule = new ScheduleService(new ScheduleGenerator(), $matchups, $teams, $settings);
$autoPick = new AutoPickStrategy($draftQueues, $draftPicks, $players);
$draftService = new DraftService(
    $pdo, $drafts, $draftPicks, $players, $autoPick, $rosters, $settings, $leagues, $schedule,
);

$stamp = date('Y-m-d H:i:s');

if ($draftService->startScheduledIfDue()) {
    echo "[{$stamp}] Scheduled start reached — the draft is now live.\n";
}

$draft = $drafts->find($leagues->currentLeagueId(), $leagues->currentSeasonId());
if ($draft !== null) {
    if ($draftService->processExpiryIfDue($draft)) {
        echo "[{$stamp}] Expired pick auto-picked.\n";
    }
    $draftService->runAutoDrafts();
}
