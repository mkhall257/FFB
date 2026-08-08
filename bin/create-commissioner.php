<?php

declare(strict_types=1);

/**
 * CLI: create a Commissioner login. Because accounts are Commissioner-
 * provisioned (there is no self-signup), this bootstraps the first admin.
 *
 * Usage (from the project root):
 *   php bin/create-commissioner.php <username> <password> [display_name]
 */

use FFB\Database;
use FFB\LeagueRepository;
use FFB\UserRepository;

require __DIR__ . '/../vendor/autoload.php';

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, "Usage: php bin/create-commissioner.php <username> <password> [display_name]\n");
    exit(1);
}

[$username, $password] = $args;
$displayName = $args[2] ?? 'Commissioner';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);
$leagueId = (new LeagueRepository($pdo))->currentLeagueId();

try {
    $id = (new UserRepository($pdo))->create($leagueId, $username, $password, 'commissioner', $displayName);
} catch (PDOException $e) {
    // Duplicate username within the League (unique key) is the common case.
    if ($e->getCode() === '23000') {
        fwrite(STDERR, "A user named '{$username}' already exists.\n");
        exit(1);
    }
    throw $e;
}

echo "Created commissioner '{$username}' (id {$id}).\n";
