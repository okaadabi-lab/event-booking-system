<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Booking\BookingStateMachine;
use App\Booking\BookingStatus;
use App\Booking\InvalidTransition;
use App\Booking\TransitionGuards;
use App\Payment\PaymentSchedule;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingStateMachineTest extends TestCase
{
    /**
     * @return iterable<string, array{BookingStatus, BookingStatus, bool}>
     */
    public static function transitions(): iterable
    {
        $allowed = [
            'inquiry>quoted', 'inquiry>cancelled', 'quoted>confirmed', 'quoted>cancelled',
            'confirmed>completed', 'confirmed>cancelled',
        ];
        foreach (BookingStatus::cases() as $from) {
            foreach (BookingStatus::cases() as $to) {
                $key = "{$from->value}>{$to->value}";
                yield $key => [$from, $to, in_array($key, $allowed, true)];
            }
        }
    }

    #[DataProvider('transitions')]
    public function testTransitionTable(BookingStatus $from, BookingStatus $to, bool $allowed): void
    {
        self::assertSame($allowed, BookingStateMachine::canTransition($from, $to));
    }

    public function testCompletedAndCancelledAreTerminal(): void
    {
        self::assertSame([], BookingStateMachine::nextStates(BookingStatus::Completed));
        self::assertSame([], BookingStateMachine::nextStates(BookingStatus::Cancelled));
    }

    public function testDisallowedTransitionThrowsReadableMessage(): void
    {
        $this->expectException(InvalidTransition::class);
        $this->expectExceptionMessage('A completed booking cannot move to cancelled.');

        BookingStateMachine::assertTransition(BookingStatus::Completed, BookingStatus::Cancelled);
    }

    public function testAllowedTransitionWithBlockersThrows(): void
    {
        $this->expectException(InvalidTransition::class);
        $this->expectExceptionMessage('cannot be confirmed yet: the deposit has not been paid in full.');

        BookingStateMachine::assertTransition(
            BookingStatus::Quoted,
            BookingStatus::Confirmed,
            ['the deposit has not been paid in full']
        );
    }

    public function testConfirmationNeedsDepositResourcesAndNoConflicts(): void
    {
        $unpaid = new PaymentSchedule(40_000_000, 30, new DateTimeImmutable('2026-11-20'), 14, 0);
        $paid = new PaymentSchedule(40_000_000, 30, new DateTimeImmutable('2026-11-20'), 14, 12_000_000);

        self::assertCount(3, TransitionGuards::forConfirmation($unpaid, 0, 1));
        self::assertSame(['assign at least one venue or crew'], TransitionGuards::forConfirmation($paid, 0, 0));
        self::assertSame(['resolve the resource scheduling conflicts'], TransitionGuards::forConfirmation($paid, 2, 1));
        self::assertSame([], TransitionGuards::forConfirmation($paid, 1, 0));
    }

    public function testCompletionOnlyAfterTheEvent(): void
    {
        $from = new DateTimeImmutable('2026-10-21 00:00');

        self::assertNotSame([], TransitionGuards::forCompletion($from, new DateTimeImmutable('2026-10-20 23:59')));
        self::assertSame([], TransitionGuards::forCompletion($from, new DateTimeImmutable('2026-10-21 00:00')));
    }

    public function testCancellationRequiresAReason(): void
    {
        self::assertNotSame([], TransitionGuards::forCancellation('   no '));
        self::assertSame([], TransitionGuards::forCancellation('Customer postponed'));
    }

    public function testQuotingNeedsPackageAndWindow(): void
    {
        self::assertCount(2, TransitionGuards::forQuote(false, false));
        self::assertSame([], TransitionGuards::forQuote(true, true));
    }
}
