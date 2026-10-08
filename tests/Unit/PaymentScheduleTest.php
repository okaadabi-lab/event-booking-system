<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Payment\PaymentSchedule;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PaymentScheduleTest extends TestCase
{
    private static function schedule(int $total, int $paid, string $eventDate = '2026-11-20', int $percent = 30): PaymentSchedule
    {
        return new PaymentSchedule($total, $percent, new DateTimeImmutable($eventDate), 14, $paid);
    }

    public function testDepositIsPercentageOfTotal(): void
    {
        self::assertSame(12_600_000, self::schedule(42_000_000, 0)->depositAmount());
    }

    public function testDepositRoundsUpToTheNextRupiah(): void
    {
        // 30% of 1.000.001 is 300.000,3: the customer pays 300.001, never less.
        self::assertSame(300_001, self::schedule(1_000_001, 0)->depositAmount());
    }

    public function testDepositPaidExactlyAtThreshold(): void
    {
        self::assertFalse(self::schedule(42_000_000, 12_599_999)->depositPaid());
        self::assertTrue(self::schedule(42_000_000, 12_600_000)->depositPaid());
    }

    public function testZeroTotalNeverCountsAsDepositPaid(): void
    {
        self::assertFalse(self::schedule(0, 0)->depositPaid());
    }

    public function testRefundsReduceWhatCountsAsPaid(): void
    {
        $schedule = self::schedule(42_000_000, 12_600_000 - 1_000_000);

        self::assertFalse($schedule->depositPaid());
        self::assertSame(1_000_000, $schedule->depositRemaining());
    }

    public function testBalanceDueDateIsDaysBeforeEvent(): void
    {
        self::assertSame('2026-11-06', self::schedule(42_000_000, 0)->balanceDueDate()->format('Y-m-d'));
    }

    public function testNotOverdueOnTheDueDateItself(): void
    {
        $schedule = self::schedule(42_000_000, 12_600_000);

        self::assertFalse($schedule->isOverdue(new DateTimeImmutable('2026-11-06 23:59')));
        self::assertTrue($schedule->isOverdue(new DateTimeImmutable('2026-11-07 00:00')));
        self::assertSame(1, $schedule->daysOverdue(new DateTimeImmutable('2026-11-07 09:00')));
    }

    public function testFullyPaidIsNeverOverdue(): void
    {
        $schedule = self::schedule(42_000_000, 42_000_000);

        self::assertFalse($schedule->isOverdue(new DateTimeImmutable('2026-12-31')));
        self::assertSame(0, $schedule->daysOverdue(new DateTimeImmutable('2026-12-31')));
        self::assertTrue($schedule->isFullyPaid());
    }

    public function testNextDueMovesFromDepositToBalanceToPaid(): void
    {
        self::assertSame(['stage' => 'deposit', 'amount' => 12_600_000, 'due' => null], self::schedule(42_000_000, 0)->nextDue());

        $balance = self::schedule(42_000_000, 12_600_000)->nextDue();
        self::assertSame('balance', $balance['stage']);
        self::assertSame(29_400_000, $balance['amount']);
        self::assertSame('2026-11-06', $balance['due']?->format('Y-m-d'));

        self::assertSame('paid', self::schedule(42_000_000, 42_000_000)->nextDue()['stage']);
    }

    public function testOverpaymentIsReportedNotHidden(): void
    {
        $schedule = self::schedule(40_000_000, 42_000_000);

        self::assertSame(0, $schedule->outstanding());
        self::assertSame(2_000_000, $schedule->overpaid());
        self::assertSame(100, $schedule->progressPercent());
    }

    public function testZeroPercentDepositIsPaidAsSoonAsThereIsATotal(): void
    {
        self::assertTrue(self::schedule(10_000_000, 0, '2026-11-20', 0)->depositPaid());
    }
}
