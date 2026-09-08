<?php

declare(strict_types=1);

/**
 * CLI: sync the canonical Player universe from Sleeper (ADR-0004, ADR-0006).
 *
 * Fetches the Sleeper players feed and the DynastyProcess id crosswalk, then
 * upserts the rosterable universe (active QB/RB/WR/TE/K and every team DEF) into
 * the `players` table — including each Player's Sleeper `search_rank`, which
 * drives the "best available first" ordering in the draft room. Each run is
 * recorded in player_sync_log so the Commissioner tools can show the last sync.
 *
 * Run it once now to populate rankings, and on a cron (e.g. daily) to keep the
 * catalog and statuses current:
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
use FFB\Players\FantasyProsDefenseRankings;
use FFB\Players\NflByeWeeks;
use FFB\Players\PlayerIdCrosswalk;
use FFB\Players\PlayerImporter;
use FFB\Players\SleeperClient;
use FFB\PlayerRepository;
use FFB\PlayerSyncLogRepository;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$players = new PlayerRepository($pdo);
$syncLog = new PlayerSyncLogRepository($pdo);
$importer = new PlayerImporter($players);

$logId = $syncLog->start();
echo "Sync #{$logId}: fetching Sleeper players and the id crosswalk…\n";

try {
    $sleeperPlayers = (new SleeperClient())->fetchPlayers();
    echo '  Sleeper feed: ' . count($sleeperPlayers) . " entries.\n";

    $crosswalk = (new PlayerIdCrosswalk())->fetch();
    echo '  Crosswalk: ' . count($crosswalk) . " id links.\n";

    $result = $importer->import($sleeperPlayers, $crosswalk);

    // Sleeper ships no rank for team defenses; apply the FantasyPros consensus
    // DST ranking so they order correctly instead of dead-last and alphabetical.
    $defenseRanks = (new FantasyProsDefenseRankings())->fetch();
    echo '  FantasyPros DST ranks: ' . count($defenseRanks) . " teams.\n";
    $rankedDefenses = $players->assignDefenseRanks($defenseRanks);

    // Bye weeks for the current NFL season (Sept–Feb belongs to the year the
    // season kicked off in), overridable via a CLI season-year argument.
    $season = isset($argv[1]) && ctype_digit((string) $argv[1])
        ? (int) $argv[1]
        : ((int) date('n') >= 3 ? (int) date('Y') : (int) date('Y') - 1);
    $byes = (new NflByeWeeks($season))->fetch();
    echo "  NFL bye weeks ({$season}): " . count($byes) . " teams.\n";
    $byePlayers = $players->assignByeWeeks($byes);

    $syncLog->finishSuccess($logId, $result->upserted, $result->unmatchedCount());

    echo "Done. Upserted {$result->upserted} players ({$result->unmatchedCount()} unmatched skill players);"
        . " ranked {$rankedDefenses} team defenses; set byes on {$byePlayers} players.\n";
} catch (\Throwable $e) {
    $syncLog->finishError($logId, $e->getMessage());
    fwrite(STDERR, "Sync failed: {$e->getMessage()}\n");
    exit(1);
}
