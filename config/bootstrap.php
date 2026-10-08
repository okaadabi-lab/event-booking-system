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
    // getenv() comes last so real process variables beat .env even when PHP's
    // variables_order leaves them out of $_ENV (the default "GPCS").
    $env = [];
    foreach (array_merge($_SERVER, $_ENV, getenv()) as $key => $value) {
        if (is_string($key) && is_string($value)) {
            $env[$key] = $value;
        }
    }

    $config = Config::fromEnvironment($env, $root);
    // DATETIME columns carry no zone; PHP must read them in the business timezone.
    date_default_timezone_set($config->timezone);

    return $config;
})();
