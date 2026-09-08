<?php

declare(strict_types=1);

/**
 * CLI: sync the canonical Player universe from Sleeper (ADR-0004, ADR-0006).
 *
 * Runs the shared {@see \FFB\Players\PlayerSync} pipeline: imports the rosterable
 * universe (active QB/RB/WR/TE/K and every team DEF) with each Player's Sleeper
 * search_rank, ranks team defenses from the FantasyPros DST consensus (Sleeper
 * ships no rank for defenses), sets the current season's bye weeks, and records
 * the run in player_sync_log. The daily cron (cron/sync_players.php) runs the
 * same pipeline.
 *
 * Run it once now to populate rankings, and on a cron (e.g. daily) to keep the
 * catalog, ranks, statuses and byes current:
 *   php bin/sync-players.php
 *
 * Bye weeks are pulled for the current NFL season. Pass a season year to
 * override the one derived from today's date (useful off-season or for testing):
 *   php bin/sync-players.php 2026
 *
 * ICDSoft cron (php83.cli, from the project root), e.g. daily at 4am:
 *   0 4 * * * /usr/local/bin/php83.cli /home/USER/ffb/bin/sync-players.php >/dev/null 2>&1
 */

use FFB\Database;
use FFB\Players\PlayerSync;
use FFB\PlayerRepository;
use FFB\PlayerSyncLogRepository;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$season = isset($argv[1]) && ctype_digit((string) $argv[1]) ? (int) $argv[1] : null;

$sync = new PlayerSync(new PlayerRepository($pdo), new PlayerSyncLogRepository($pdo));
echo "Syncing Sleeper players, FantasyPros DST ranks and byes…\n";

try {
    $r = $sync->run($season);
    echo "Done (sync #{$r->runId}). Upserted {$r->playersUpserted} players ({$r->unmatched} unmatched skill players);"
        . " ranked {$r->defensesRanked} team defenses (from {$r->defenseRanksAvailable} FantasyPros DST ranks);"
        . " set byes on {$r->byesSet} players (season {$r->season}, {$r->byesAvailable} teams).\n";

    if ($r->defenseRanksAvailable === 0) {
        fwrite(STDERR, "Warning: the FantasyPros DST feed returned no ranks — defenses were"
            . " ordered alphabetically. Check network access to the DynastyProcess mirror.\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "Sync failed: {$e->getMessage()}\n");
    exit(1);
}
