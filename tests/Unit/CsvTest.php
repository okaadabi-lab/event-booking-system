<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    public function testNeutralisesSpreadsheetFormulas(): void
    {
        self::assertSame("'=HYPERLINK(\"x\")", Csv::cell('=HYPERLINK("x")'));
        self::assertSame("'+62812", Csv::cell('+62812'));
        self::assertSame("'@SUM(A1)", Csv::cell('@SUM(A1)'));
        self::assertSame('Ayu Pratiwi', Csv::cell('Ayu Pratiwi'));
    }

    public function testKeepsNegativeAmountsNumeric(): void
    {
        self::assertSame('-2500000', Csv::cell(-2_500_000));
    }

    public function testBuildsQuotedRows(): void
    {
        $csv = Csv::build(['customer', 'amount'], [['Putri, Handayani', 100]]);

        self::assertSame("customer,amount\n\"Putri, Handayani\",100\n", $csv);
    }
}
