<?php

declare(strict_types=1);

/**
 * Front controller — the only PHP file exposed under the web document root.
 * Adapts PHP's superglobals and native session to the application Kernel.
 */

use FFB\Database;
use FFB\Http\PhpSession;
use FFB\Http\Request;
use FFB\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$config = require __DIR__ . '/../config/config.php';
$pdo = Database::connect($config['db']);

$router = Kernel::router($pdo);
$response = $router->dispatch(Request::fromGlobals(), new PhpSession());
$response->send();
