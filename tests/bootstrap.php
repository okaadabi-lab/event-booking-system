<?php

declare(strict_types=1);

use App\Support\Config;
use App\Support\Database;
use App\Support\Migrator;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$env = [];
foreach (array_merge($_SERVER, $_ENV, getenv()) as $key => $value) {
    if (is_string($key) && is_string($value)) {
        $env[$key] = $value;
    }
}
$testDatabase = $env['DB_TEST_DATABASE'] ?? 'event_booking_test';
if (!str_ends_with($testDatabase, '_test')) {
    fwrite(STDERR, "DB_TEST_DATABASE must end in \"_test\"; the suite truncates its tables.\n");
    exit(1);
}

$config = Config::fromEnvironment(array_merge($env, [
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'false',
    'DB_DATABASE' => $testDatabase,
    'LOG_PATH' => 'storage/logs/test.log',
    'DEPOSIT_PERCENT' => '30',
    'BALANCE_DUE_DAYS_BEFORE_EVENT' => '14',
    'RESOURCE_BUFFER_MINUTES' => '120',
    'LOGIN_MAX_FAILURES' => '5',
    'LOGIN_WINDOW_MINUTES' => '15',
    'INQUIRY_MAX_PER_HOUR' => '5',
]), $root);

date_default_timezone_set($config->timezone);

$migrator = new Migrator(Database::fromConfig($config), $root . '/database/migrations');
$migrator->dropAllTables();
$migrator->migrate();

Tests\Support\TestConfig::set($config);
