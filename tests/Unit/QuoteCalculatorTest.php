<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Booking\QuoteCalculator;
use App\Booking\QuoteLine;
use App\Support\DomainRuleViolation;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

final class QuoteCalculatorTest extends TestCase
{
    public function testSumsLinesAndAppliesDiscount(): void
    {
        $totals = QuoteCalculator::calculate([
            new QuoteLine('package', 1, 'Resepsi Classic', 42_000_000, 42_000_000, 1),
            new QuoteLine('addon', 3, 'Fresh flowers', 750_000, 750_000, 6),
        ], 2_500_000);

        self::assertSame(46_500_000, $totals->subtotal);
        self::assertSame(2_500_000, $totals->discount);
        self::assertSame(44_000_000, $totals->total);
    }

    public function testUsesTheQuotedUnitPriceNotTheCatalogPrice(): void
    {
        $line = new QuoteLine('package', 1, 'Akad', 18_500_000, 17_000_000, 1);

        self::assertTrue($line->isPriceOverridden());
        self::assertSame(17_000_000, QuoteCalculator::calculate([$line], 0)->total);
    }

    public function testEmptyQuoteTotalsZero(): void
    {
        $totals = QuoteCalculator::calculate([], 0);

        self::assertSame(0, $totals->subtotal);
        self::assertSame(0, $totals->total);
    }

    public function testDiscountCanEqualSubtotal(): void
    {
        $totals = QuoteCalculator::calculate([new QuoteLine('addon', 1, 'Chairs', 450_000, 450_000, 2)], 900_000);

        self::assertSame(0, $totals->total);
    }

    public function testRejectsDiscountLargerThanSubtotal(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('larger than the subtotal');

        QuoteCalculator::calculate([new QuoteLine('addon', 1, 'Chairs', 450_000, 450_000, 1)], 450_001);
    }

    public function testRejectsNegativeDiscount(): void
    {
        $this->expectException(DomainRuleViolation::class);

        QuoteCalculator::calculate([new QuoteLine('addon', 1, 'Chairs', 450_000, 450_000, 1)], -1);
    }

    public function testRejectsTotalsAboveTheSystemLimit(): void
    {
        $this->expectException(DomainRuleViolation::class);

        QuoteCalculator::calculate([new QuoteLine('addon', 1, 'Gold', Money::MAX_AMOUNT, Money::MAX_AMOUNT, 2)], 0);
    }

    public function testRejectsZeroQuantity(): void
    {
        $this->expectException(DomainRuleViolation::class);

        new QuoteLine('addon', 1, 'Chairs', 450_000, 450_000, 0);
    }

    public function testRejectsUnknownItemType(): void
    {
        $this->expectException(DomainRuleViolation::class);

        new QuoteLine('service', 1, 'Chairs', 450_000, 450_000, 1);
    }
}
