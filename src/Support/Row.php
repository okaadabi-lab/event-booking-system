<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use UnexpectedValueException;

/**
 * Typed readers for database rows, so a wrong column name or type fails loudly
 * instead of silently producing null or 0.
 */
final class Row
{
    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $value = self::value($row, $key);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw new UnexpectedValueException("Column \"$key\" is not an integer.");
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableInt(array $row, string $key): ?int
    {
        return self::value($row, $key) === null ? null : self::int($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $key): string
    {
        $value = self::value($row, $key);
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        throw new UnexpectedValueException("Column \"$key\" is not a string.");
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $key): ?string
    {
        return self::value($row, $key) === null ? null : self::string($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function bool(array $row, string $key): bool
    {
        return self::int($row, $key) === 1;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function date(array $row, string $key): DateTimeImmutable
    {
        $value = self::string($row, $key);
        $date = new DateTimeImmutable($value);

        return $date;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableDate(array $row, string $key): ?DateTimeImmutable
    {
        return self::value($row, $key) === null ? null : self::date($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function value(array $row, string $key): mixed
    {
        if (!array_key_exists($key, $row)) {
            throw new UnexpectedValueException("Column \"$key\" is missing from the result row.");
        }

        return $row[$key];
    }
}
