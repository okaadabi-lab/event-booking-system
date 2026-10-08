<?php

declare(strict_types=1);

namespace App\Support;

final class Csv
{
    /**
     * @param list<string> $header
     * @param list<list<string|int>> $rows
     */
    public static function build(array $header, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream for CSV output.');
        }
        fputcsv($handle, $header, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Spreadsheet apps execute cells that start with = + - @ as formulas, so text
     * cells starting with those characters get a leading apostrophe. Integers are
     * written as numbers (refunds are legitimately negative).
     */
    public static function cell(string|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }
}
