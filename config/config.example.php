<?php

declare(strict_types=1);

/**
 * FFB configuration.
 *
 * Copy this file to `config/config.php` (which is git-ignored) and fill in the
 * real values for the environment. Never commit `config/config.php`.
 */

return [
    // MySQL connection used by the application at runtime.
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => '3306',
        'database' => 'ffb',
        'username' => 'root',
        'password' => '',
    ],

    // The NFL Season the site currently runs (see ADR-0001).
    'season_year' => 2026,
];
