<?php

declare(strict_types=1);

/**
 * Player sync — run as a scheduled ICDSoft cron job.
 *
 * Runs the shared {@see \FFB\Players\PlayerSync} pipeline: imports the canonical
 * Player universe from Sleeper (+ nflverse id crosswalk), ranks team defenses
 * from the FantasyPros DST consensus, sets the current season's bye weeks, and
 * records the run in player_sync_log. The CLI (bin/sync-players.php) runs the
 * exact same pipeline — do NOT re-import here without also ranking defenses, or
 * defenses fall back to unranked/alphabetical (see PlayerSync's docblock).
 *
 * Usage:
 *   php cron/sync_players.php
 */

use FFB\Database;
use FFB\Players\PlayerSync;
use FFB\PlayerRepository;
use FFB\PlayerSyncLogRepository;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$sync = new PlayerSync(new PlayerRepository($pdo), new PlayerSyncLogRepository($pdo));

try {
    $r = $sync->run();
    echo "Sync #{$r->runId}: upserted {$r->playersUpserted} players ({$r->unmatched} unmatched);"
        . " ranked {$r->defensesRanked} defenses; set byes on {$r->byesSet} players.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Sync failed: {$e->getMessage()}\n");
    exit(1);
}
