<?php

declare(strict_types=1);

/**
 * CLI: reset the current League + Season to a clean pre-Draft state.
 *
 * Wipes every Team, every Manager login, and all of their history (draft,
 * rosters, lineups, matchups, trades, playoff seeds) so a fresh, official
 * Draft can be run. KEEPS the League, Season, league_settings, players, and
 * all Commissioner logins.
 *
 * Runs as a DRY-RUN by default, printing the row counts it would delete.
 * Pass --yes to actually perform the deletions (wrapped in one transaction).
 *
 * Usage (from the project root):
 *   php bin/reset-league.php          # dry run: show what would be deleted
 *   php bin/reset-league.php --yes    # perform the reset
 */

use FFB\Database;
use FFB\LeagueRepository;

require __DIR__ . '/../vendor/autoload.php';

$confirmed = in_array('--yes', array_slice($argv, 1), true);

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$leagues = new LeagueRepository($pdo);
$leagueId = $leagues->currentLeagueId();
$seasonId = $leagues->currentSeasonId();

echo "League {$leagueId}, Season {$seasonId} — target database: {$config['db']['database']} @ {$config['db']['host']}\n\n";

/**
 * Delete order matters: children (rows referencing a team) before the teams
 * themselves, then the manager logins. Each entry is [label, SQL, params].
 * All statements are scoped to the current League + Season so nothing else
 * is touched.
 */
$teamIdsSql = 'SELECT id FROM teams WHERE league_id = ? AND season_id = ?';
$ls = [$leagueId, $seasonId];

$steps = [
    ['draft_queue',       "DELETE FROM draft_queue       WHERE team_id IN ($teamIdsSql)", $ls],
    ['draft_picks',       "DELETE FROM draft_picks       WHERE team_id IN ($teamIdsSql)", $ls],
    ['draft_order',       "DELETE FROM draft_order       WHERE team_id IN ($teamIdsSql)", $ls],
    ['transaction_items', "DELETE FROM transaction_items WHERE from_team_id IN ($teamIdsSql) OR to_team_id IN ($teamIdsSql)", [...$ls, ...$ls]],
    ['transactions',      "DELETE FROM transactions      WHERE league_id = ? AND season_id = ?", $ls],
    ['lineups',           "DELETE FROM lineups           WHERE team_id IN ($teamIdsSql)", $ls],
    ['rosters',           "DELETE FROM rosters           WHERE team_id IN ($teamIdsSql)", $ls],
    ['playoff_seeds',     "DELETE FROM playoff_seeds     WHERE team_id IN ($teamIdsSql)", $ls],
    ['matchups',          "DELETE FROM matchups          WHERE league_id = ? AND season_id = ?", $ls],
    ['drafts',            "DELETE FROM drafts            WHERE league_id = ? AND season_id = ?", $ls],
    ['teams',             "DELETE FROM teams             WHERE league_id = ? AND season_id = ?", $ls],
    // Manager logins for this League (Commissioners are preserved).
    ['users (managers)',  "DELETE FROM users            WHERE league_id = ? AND role = 'manager'", [$leagueId]],
];

if (!$confirmed) {
    echo "DRY RUN — nothing will be deleted. Counts that WOULD be removed:\n";
    foreach ($steps as [$label, $sql, $params]) {
        $countSql = preg_replace('/^DELETE FROM (\S+)\s+/', 'SELECT COUNT(*) FROM $1 ', $sql, 1);
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        printf("  %-20s %d\n", $label, (int) $stmt->fetchColumn());
    }
    echo "\nRe-run with --yes to perform the reset.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    echo "Deleting:\n";
    foreach ($steps as [$label, $sql, $params]) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        printf("  %-20s %d rows\n", $label, $stmt->rowCount());
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nReset failed, rolled back: {$e->getMessage()}\n");
    exit(1);
}

echo "\nDone. League is reset to a clean pre-Draft state. Recreate teams in /admin.\n";
