<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\AccessDenied;
use App\Auth\Role;
use App\Auth\User;
use App\Booking\BookingRepository;
use App\Booking\BookingService;
use App\Operations\OperationsBoard;
use App\Payment\PaymentKind;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRepository;
use App\Payment\PaymentService;
use App\Support\DomainRuleViolation;
use Tests\Support\DatabaseTestCase;

final class PaymentServiceTest extends DatabaseTestCase
{
    private User $sales;
    private User $finance;
    private PaymentService $payments;
    private int $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sales = $this->makeUser(Role::Sales);
        $this->finance = $this->makeUser(Role::Finance);
        $this->payments = $this->container->make(PaymentService::class);
        $this->booking = $this->makeBooking($this->sales, packageId: $this->makePackage('Akad', 20_000_000));
    }

    public function testRecordsPaymentAndRefundAndKeepsNetBalance(): void
    {
        $this->record(PaymentKind::Payment, 6_000_000);
        $this->record(PaymentKind::Refund, 1_000_000);

        self::assertSame(5_000_000, $this->container->make(BookingRepository::class)->paidNet($this->booking));
        self::assertSame(['created', 'status_changed', 'payment_recorded', 'refund_recorded'], $this->activityActions($this->booking));
    }

    public function testCannotPayMoreThanTheTotal(): void
    {
        $this->record(PaymentKind::Payment, 19_000_000);

        $this->expectExceptionMessage('At most Rp 1.000.000 is still outstanding');
        $this->record(PaymentKind::Payment, 1_000_001);
    }

    public function testCannotRefundMoreThanReceived(): void
    {
        $this->record(PaymentKind::Payment, 6_000_000);

        $this->expectException(DomainRuleViolation::class);
        $this->record(PaymentKind::Refund, 6_000_001);
    }

    public function testPaymentDateCannotBeInTheFuture(): void
    {
        $this->expectExceptionMessage('cannot be in the future');
        $this->payments->record(
            $this->booking,
            PaymentKind::Payment,
            1_000_000,
            PaymentMethod::Cash,
            '',
            self::at('2026-10-09 10:01'),
            $this->finance
        );
    }

    public function testSalesCannotRecordPayments(): void
    {
        $this->expectException(AccessDenied::class);
        $paidAt = self::at('2026-10-09 09:00');
        $this->payments->record($this->booking, PaymentKind::Payment, 1, PaymentMethod::Cash, '', $paidAt, $this->sales);
    }

    public function testNoPaymentsOnUnquotedInquiry(): void
    {
        $inquiry = $this->makeBooking($this->sales, quoted: false, phone: '+6281200000002');

        $this->expectExceptionMessage('cannot be recorded on a inquiry booking');
        $this->record(PaymentKind::Payment, 1_000_000, $inquiry);
    }

    public function testRefundAllowedAfterCancellationButNotNewPayments(): void
    {
        $this->record(PaymentKind::Payment, 6_000_000);
        $this->container->make(BookingService::class)->cancel($this->booking, 'Customer cancelled the event', $this->sales);

        $this->record(PaymentKind::Refund, 4_800_000);
        self::assertSame(1_200_000, $this->container->make(BookingRepository::class)->paidNet($this->booking));

        $this->expectException(DomainRuleViolation::class);
        $this->record(PaymentKind::Payment, 100_000);
    }

    public function testReceivablesFlagOverdueBalances(): void
    {
        // Event on 20 Oct, balance due 6 Oct; today is 9 Oct.
        $this->record(PaymentKind::Payment, 6_000_000);
        $board = $this->container->make(OperationsBoard::class);

        $entries = $board->receivables(self::at('2026-10-09 10:00'));

        self::assertCount(1, $entries);
        self::assertTrue($entries[0]['overdue']);
        self::assertSame(3, $entries[0]['days_overdue']);
        self::assertSame(14_000_000, $entries[0]['schedule']->outstanding());
    }

    public function testMonthlyExportQueryIncludesOnlyThatMonth(): void
    {
        $this->record(PaymentKind::Payment, 1_000_000, null, '2026-09-30 23:59');
        $this->record(PaymentKind::Payment, 2_000_000, null, '2026-10-01 00:00');
        $this->record(PaymentKind::Refund, 500_000, null, '2026-10-09 08:00');

        $rows = $this->container->make(PaymentRepository::class)->receivedInMonth(self::at('2026-10-15'));

        self::assertSame([2_000_000, 500_000], array_map(static fn (array $r): int => (int) $r['amount'], $rows));
        self::assertSame(['payment', 'refund'], array_column($rows, 'kind'));
    }

    private function record(PaymentKind $kind, int $amount, ?int $booking = null, string $paidAt = '2026-10-09 09:00'): void
    {
        $this->payments->record(
            $booking ?? $this->booking,
            $kind,
            $amount,
            PaymentMethod::BankTransfer,
            'TRF-' . $amount,
            self::at($paidAt),
            $this->finance,
            $kind === PaymentKind::Refund ? 'Test refund' : ''
        );
    }
}
