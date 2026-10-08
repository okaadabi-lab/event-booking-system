<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Amounts are whole rupiah held in integers end to end; this class only parses and formats.
 */
final class Money
{
    public const MAX_AMOUNT = 100_000_000_000;

    public static function format(int $amount): string
    {
        $sign = $amount < 0 ? '-' : '';

        return $sign . 'Rp ' . number_format(abs($amount), 0, ',', '.');
    }

    /**
     * Accepts "1500000", "1.500.000", "Rp 1.500.000" or "1,500,000". Returns null for
     * anything else, including decimals: rupiah amounts here are always whole numbers.
     */
    public static function parse(string $input): ?int
    {
        $clean = trim(str_ireplace('rp', '', $input));
        $clean = str_replace([' ', "\u{00A0}"], '', $clean);
        if ($clean === '') {
            return null;
        }
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $clean) === 1) {
            $clean = str_replace(['.', ','], '', $clean);
        }
        if (preg_match('/^\d{1,12}$/', $clean) !== 1) {
            return null;
        }
        $value = (int) $clean;

        return $value <= self::MAX_AMOUNT ? $value : null;
    }
}
