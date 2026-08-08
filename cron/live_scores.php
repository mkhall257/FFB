<?php

declare(strict_types=1);

/**
 * Live scoring — run frequently during NFL game windows (ICDSoft cron).
 *
 * Fetches Sleeper's weekly stats, upserts the provisional Live lines, and
 * recomputes every Matchup's cached score for the current week (status 'live').
 * The current week and season year are Commissioner-maintained settings
 * (schedule.current_week, schedule.season_year).
 *
 * Usage:
 *   php cron/live_scores.php
 */

use FFB\Database;
use FFB\LeagueRepository;
use FFB\LeagueSettingsRepository;
use FFB\LineupRepository;
use FFB\MatchupRepository;
use FFB\PlayerRepository;
use FFB\PlayerWeekStatsRepository;
use FFB\Scoring\MatchupScoringService;
use FFB\Scoring\ScoringEngine;
use FFB\Scoring\SleeperStatsClient;
use FFB\Scoring\StatsImporter;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$leagues = new LeagueRepository($pdo);
$leagueId = $leagues->currentLeagueId();
$seasonId = $leagues->currentSeasonId();

$settings = new LeagueSettingsRepository($pdo);
$all = $settings->all($leagueId, $seasonId);
$week = (int) ($all['schedule.current_week'] ?? 0);
$season = (int) ($all['schedule.season_year'] ?? date('Y'));
if ($week < 1) {
    fwrite(STDERR, "No current week set (schedule.current_week); nothing to score.\n");
    exit(0);
}

try {
    $stats = new PlayerWeekStatsRepository($pdo);
    $importer = new StatsImporter($stats, new PlayerRepository($pdo));
    $lines = (new SleeperStatsClient())->fetchWeek($season, $week);
    $written = $importer->importSleeper($seasonId, $week, $lines);

    $scoring = new MatchupScoringService(
        new MatchupRepository($pdo),
        new LineupRepository($pdo),
        $stats,
        new ScoringEngine(),
        $settings,
    );
    $scoring->scoreWeek($leagueId, $seasonId, $week, 'live');

    echo "Live scoring week {$week}: {$written} stat lines, matchups updated.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Live scoring week {$week} failed: {$e->getMessage()}\n");
    exit(1);
}
