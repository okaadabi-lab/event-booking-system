<?php

declare(strict_types=1);

namespace App\Payment;

use App\Activity\ActivityLog;
use App\Auth\AccessDenied;
use App\Auth\Ability;
use App\Auth\Policy;
use App\Auth\User;
use App\Booking\BookingRepository;
use App\Support\Clock;
use App\Support\Database;
use App\Support\DomainRuleViolation;
use App\Support\Money;
use DateTimeImmutable;

final class PaymentService
{
    public function __construct(
        private readonly Database $db,
        private readonly BookingRepository $bookings,
        private readonly ActivityLog $activity,
        private readonly Policy $policy,
        private readonly Clock $clock,
    ) {
    }

    public function record(
        int $bookingId,
        PaymentKind $kind,
        int $amount,
        PaymentMethod $method,
        string $reference,
        DateTimeImmutable $paidAt,
        User $actor,
        string $note = '',
    ): int {
        $ability = $kind === PaymentKind::Payment ? Ability::RecordPayment : Ability::RecordRefund;
        $this->policy->authorize($actor, $ability);

        return $this->db->transactional(function () use (
            $bookingId,
            $kind,
            $amount,
            $method,
            $reference,
            $paidAt,
            $actor,
            $note,
            $ability
        ): int {
            // The booking lock serialises payments per booking so the overpayment and
            // refund checks below see every earlier payment.
            $booking = $this->bookings->lockForUpdate($bookingId) ?? throw new DomainRuleViolation('Booking not found.');
            if (!$this->policy->allowsOnBooking($actor, $ability, $booking->status)) {
                if (!$this->policy->allows($actor, $ability)) {
                    throw new AccessDenied('Your role does not allow this action.');
                }
                throw new DomainRuleViolation(
                    $kind === PaymentKind::Payment
                        ? "Payments cannot be recorded on a {$booking->status->value} booking."
                        : "Refunds cannot be recorded on a {$booking->status->value} booking."
                );
            }
            if ($amount <= 0 || $amount > Money::MAX_AMOUNT) {
                throw new DomainRuleViolation('The amount must be greater than zero.');
            }
            if ($paidAt > $this->clock->now()) {
                throw new DomainRuleViolation('The payment date cannot be in the future.');
            }

            $paidNet = $this->bookings->paidNet($bookingId);
            if ($kind === PaymentKind::Payment && $paidNet + $amount > $booking->total) {
                throw new DomainRuleViolation(sprintf(
                    'This payment would exceed the booking total. At most %s is still outstanding.',
                    Money::format(max(0, $booking->total - $paidNet))
                ));
            }
            if ($kind === PaymentKind::Refund && $amount > $paidNet) {
                throw new DomainRuleViolation(sprintf(
                    'A refund cannot be larger than the %s received so far.',
                    Money::format(max(0, $paidNet))
                ));
            }

            $id = $this->db->insert(
                'INSERT INTO payments (booking_id, kind, amount, method, reference, note, paid_at, recorded_by)
                 VALUES (:booking_id, :kind, :amount, :method, :reference, :note, :paid_at, :recorded_by)',
                [
                    'booking_id' => $bookingId,
                    'kind' => $kind->value,
                    'amount' => $amount,
                    'method' => $method->value,
                    'reference' => $reference,
                    'note' => $note,
                    'paid_at' => $paidAt->format('Y-m-d H:i:s'),
                    'recorded_by' => $actor->id,
                ]
            );

            $newPaidNet = $kind === PaymentKind::Payment ? $paidNet + $amount : $paidNet - $amount;
            $this->activity->record(
                'booking',
                $bookingId,
                $kind === PaymentKind::Payment ? 'payment_recorded' : 'refund_recorded',
                sprintf(
                    '%s of %s recorded (%s)',
                    $kind === PaymentKind::Payment ? 'Payment' : 'Refund',
                    Money::format($amount),
                    $method->label()
                ),
                $actor->id,
                ['paid_net' => $paidNet],
                ['paid_net' => $newPaidNet, 'reference' => $reference]
            );

            return $id;
        });
    }
}
