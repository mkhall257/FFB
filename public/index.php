<?php

declare(strict_types=1);

/**
 * Front controller. The only PHP file exposed under the web document root;
 * everything else lives outside `public/`.
 *
 * For now this is a placeholder health check. Routing, auth, and the admin
 * screens are added in later slices of Wave 1.
 */

require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: text/plain; charset=utf-8');
echo "FFB is running.\n";
