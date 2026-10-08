<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Config;
use RuntimeException;

final class TestConfig
{
    private static ?Config $config = null;

    public static function set(Config $config): void
    {
        self::$config = $config;
    }

    public static function get(): Config
    {
        return self::$config ?? throw new RuntimeException('tests/bootstrap.php did not run.');
    }
}
