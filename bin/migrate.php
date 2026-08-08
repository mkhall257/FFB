<?php

declare(strict_types=1);

/**
 * CLI entry point: apply all pending migrations to the configured database.
 *
 * Usage (from the project root):
 *   php bin/migrate.php
 *
 * Reads config/config.php. Creates the target database if it does not exist.
 */

use FFB\Database;
use FFB\Migrator;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$db = $config['db'];

// Ensure the target database exists before connecting to it.
$server = Database::connectServer($db);
$server->exec(sprintf(
    'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    str_replace('`', '``', $db['database'])
));

$pdo = Database::connect($db);
$migrator = new Migrator($pdo);

$applied = $migrator->migrate(__DIR__ . '/../migrations');

if ($applied === []) {
    echo "Database is up to date; no migrations applied.\n";
} else {
    echo "Applied " . count($applied) . " migration(s):\n";
    foreach ($applied as $version) {
        echo "  - {$version}\n";
    }
}
