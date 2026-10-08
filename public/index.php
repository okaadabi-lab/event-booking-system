<?php

declare(strict_types=1);

use App\Support\Config;

$config = require dirname(__DIR__) . '/config/bootstrap.php';
assert($config instanceof Config);

// When run with `php -S`, let the built-in server serve existing static files.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_string($path) && $path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
}

(require dirname(__DIR__) . '/config/app.php')($config)->run();
