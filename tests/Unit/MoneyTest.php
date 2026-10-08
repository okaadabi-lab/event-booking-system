<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function inputs(): iterable
    {
        yield 'plain digits' => ['1500000', 1_500_000];
        yield 'dot thousands' => ['1.500.000', 1_500_000];
        yield 'comma thousands' => ['1,500,000', 1_500_000];
        yield 'with Rp prefix' => ['Rp 1.500.000', 1_500_000];
        yield 'zero' => ['0', 0];
        yield 'empty' => ['', null];
        yield 'decimal' => ['1500.50', null];
        yield 'negative' => ['-5000', null];
        yield 'misgrouped' => ['1.50.000', null];
        yield 'letters' => ['15 juta', null];
        yield 'above limit' => ['100000000001', null];
    }

    #[DataProvider('inputs')]
    public function testParse(string $input, ?int $expected): void
    {
        self::assertSame($expected, Money::parse($input));
    }

    public function testFormatUsesIndonesianGrouping(): void
    {
        self::assertSame('Rp 42.000.000', Money::format(42_000_000));
        self::assertSame('-Rp 5.000', Money::format(-5_000));
    }
}
