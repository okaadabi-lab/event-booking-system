<?php

declare(strict_types=1);

use App\Support\Config;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Loads .env when present (real environment variables win) and returns validated config.
return (static function (): Config {
    $root = dirname(__DIR__);
    if (is_file($root . '/.env')) {
        Dotenv::createImmutable($root)->safeLoad();
    }
    $env = [];
    foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $env[$key] = $value;
        }
    }

    return Config::fromEnvironment($env, $root);
})();
