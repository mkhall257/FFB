<?php

declare(strict_types=1);

/**
 * Official settlement — run daily (ICDSoft cron), a day or two after a week's
 * games. Ingests nflverse official stats for the target week, rescores Matchups
 * as final, and locks the week (ADR-0005). May change a result; Standings then
 * reflect the settled outcome.
 *
 * The week to settle is schedule.settle_week, defaulting to the week before
 * schedule.current_week.
 *
 * Usage:
 *   php cron/settle_official.php
 */

use FFB\Database;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Scoring\MatchupScoringService;
use FFB\Scoring\NflverseStatsClient;
use FFB\Scoring\ScoringEngine;
use FFB\Scoring\SettlementService;
use FFB\Scoring\StatsImporter;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$leagues = new LeagueRepository($pdo);
$leagueId = $leagues->currentLeagueId();
$seasonId = $leagues->currentSeasonId();

$settings = new LeagueSettingsRepository($pdo);
$all = $settings->all($leagueId, $seasonId);
$week = (int) ($all['schedule.settle_week'] ?? ((int) ($all['schedule.current_week'] ?? 1) - 1));
$season = (int) ($all['schedule.season_year'] ?? date('Y'));
if ($week < 1) {
    fwrite(STDERR, "No week to settle yet.\n");
    exit(0);
}

try {
    $stats = new PlayerWeekStatsRepository($pdo);
    $importer = new StatsImporter($stats, new PlayerRepository($pdo));
    $scoring = new MatchupScoringService(
        new MatchupRepository($pdo),
        new LineupRepository($pdo),
        $stats,
        new ScoringEngine(),
        $settings,
    );
    $settlement = new SettlementService($importer, $scoring, new MatchupRepository($pdo));

    $lines = (new NflverseStatsClient())->fetchWeek($season, $week);
    $settlement->settleWeek($leagueId, $seasonId, $week, $lines);

    echo "Settled week {$week} to official (" . count($lines) . " official stat lines).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Settling week {$week} failed: {$e->getMessage()}\n");
    exit(1);
}
