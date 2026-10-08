<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class Config
{
    public const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private function __construct(
        public readonly string $rootDir,
        public readonly string $appName,
        public readonly string $env,
        public readonly bool $debug,
        public readonly string $timezone,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $sessionSecureCookie,
        public readonly string $logPath,
        public readonly string $logLevel,
        public readonly int $depositPercent,
        public readonly int $balanceDueDaysBeforeEvent,
        public readonly int $resourceBufferMinutes,
        public readonly int $loginMaxFailures,
        public readonly int $loginWindowMinutes,
        public readonly int $inquiryMaxPerHour,
    ) {
    }

    /**
     * @param array<string, string|null> $env
     */
    public static function fromEnvironment(array $env, string $rootDir): self
    {
        $read = static fn (string $key, string $default): string =>
            isset($env[$key]) && $env[$key] !== '' ? (string) $env[$key] : $default;

        $appEnv = $read('APP_ENV', 'production');
        if (!in_array($appEnv, ['development', 'production', 'testing'], true)) {
            throw new InvalidArgumentException("APP_ENV must be development, production or testing, got \"$appEnv\".");
        }

        $secure = $read('SESSION_SECURE_COOKIE', 'auto');
        if (!in_array($secure, ['auto', 'true', 'false'], true)) {
            throw new InvalidArgumentException('SESSION_SECURE_COOKIE must be auto, true or false.');
        }

        $logLevel = strtolower($read('LOG_LEVEL', 'info'));
        if (!in_array($logLevel, self::LOG_LEVELS, true)) {
            throw new InvalidArgumentException('LOG_LEVEL must be one of ' . implode(', ', self::LOG_LEVELS) . '.');
        }

        $logPath = $read('LOG_PATH', 'storage/logs/app.log');
        if (!str_starts_with($logPath, '/') && $logPath !== 'php://stderr') {
            $logPath = $rootDir . '/' . $logPath;
        }

        return new self(
            rootDir: $rootDir,
            appName: $read('APP_NAME', 'Event Booking'),
            env: $appEnv,
            // Debug output is never allowed in production, whatever APP_DEBUG says.
            debug: $appEnv !== 'production' && filter_var($read('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOL),
            timezone: $read('APP_TIMEZONE', 'Asia/Jakarta'),
            dbHost: $read('DB_HOST', '127.0.0.1'),
            dbPort: self::intInRange($read('DB_PORT', '3306'), 'DB_PORT', 1, 65535),
            dbName: $read('DB_DATABASE', 'event_booking'),
            dbUser: $read('DB_USERNAME', 'root'),
            dbPassword: $env['DB_PASSWORD'] ?? '',
            sessionSecureCookie: $secure,
            logPath: $logPath,
            logLevel: $logLevel,
            depositPercent: self::intInRange($read('DEPOSIT_PERCENT', '30'), 'DEPOSIT_PERCENT', 0, 100),
            balanceDueDaysBeforeEvent: self::intInRange(
                $read('BALANCE_DUE_DAYS_BEFORE_EVENT', '14'),
                'BALANCE_DUE_DAYS_BEFORE_EVENT',
                0,
                365
            ),
            resourceBufferMinutes: self::intInRange(
                $read('RESOURCE_BUFFER_MINUTES', '120'),
                'RESOURCE_BUFFER_MINUTES',
                0,
                24 * 60
            ),
            loginMaxFailures: self::intInRange($read('LOGIN_MAX_FAILURES', '5'), 'LOGIN_MAX_FAILURES', 1, 100),
            loginWindowMinutes: self::intInRange($read('LOGIN_WINDOW_MINUTES', '15'), 'LOGIN_WINDOW_MINUTES', 1, 1440),
            inquiryMaxPerHour: self::intInRange($read('INQUIRY_MAX_PER_HOUR', '5'), 'INQUIRY_MAX_PER_HOUR', 1, 1000),
        );
    }

    public function isProduction(): bool
    {
        return $this->env === 'production';
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function with(array $overrides): self
    {
        $values = get_object_vars($this);
        foreach ($overrides as $key => $value) {
            if (!array_key_exists($key, $values)) {
                throw new InvalidArgumentException("Unknown config key \"$key\".");
            }
            $values[$key] = $value;
        }

        return new self(...$values);
    }

    private static function intInRange(string $value, string $name, int $min, int $max): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($int === false) {
            throw new InvalidArgumentException("$name must be an integer between $min and $max.");
        }

        return $int;
    }
}
