<?php

declare(strict_types=1);

/**
 * Player sync — run as a scheduled ICDSoft cron job.
 *
 * Fetches the Sleeper players feed and the DynastyProcess id crosswalk,
 * upserts the canonical Player universe, links nflverse ids, and records the
 * run (with the Unmatched count) in player_sync_log.
 *
 * Usage:
 *   php cron/sync_players.php
 */

use FFB\Database;
use FFB\Players\PlayerIdCrosswalk;
use FFB\Players\PlayerImporter;
use FFB\Players\SleeperClient;
use FFB\PlayerRepository;
use FFB\PlayerSyncLogRepository;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$log = new PlayerSyncLogRepository($pdo);
$runId = $log->start();

try {
    $sleeperPlayers = (new SleeperClient())->fetchPlayers();
    $crosswalk = (new PlayerIdCrosswalk())->fetch();

    $result = (new PlayerImporter(new PlayerRepository($pdo)))->import($sleeperPlayers, $crosswalk);

    $log->finishSuccess($runId, $result->upserted, $result->unmatchedCount());
    echo "Sync #{$runId}: upserted {$result->upserted} players, {$result->unmatchedCount()} unmatched.\n";
} catch (\Throwable $e) {
    $log->finishError($runId, $e->getMessage());
    fwrite(STDERR, "Sync #{$runId} failed: {$e->getMessage()}\n");
    exit(1);
}
