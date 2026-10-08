<?php

declare(strict_types=1);

namespace App\Booking;

use App\Customer\Customer;
use App\Payment\PaymentSchedule;
use App\Resource\TimeWindow;
use App\Support\Row;
use DateTimeImmutable;

final class Booking
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly Customer $customer,
        public readonly BookingStatus $status,
        public readonly string $source,
        public readonly DateTimeImmutable $eventDate,
        public readonly string $location,
        public readonly int $guestCount,
        public readonly ?TimeWindow $window,
        public readonly int $subtotal,
        public readonly int $discountAmount,
        public readonly ?string $discountReason,
        public readonly int $total,
        public readonly int $depositPercent,
        public readonly ?string $notes,
        public readonly ?string $cancelReason,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row booking columns plus customer_name/phone/email
     */
    public static function fromRow(array $row): self
    {
        $start = Row::nullableDate($row, 'setup_start');
        $end = Row::nullableDate($row, 'teardown_end');

        return new self(
            Row::int($row, 'id'),
            Row::string($row, 'code'),
            new Customer(
                Row::int($row, 'customer_id'),
                Row::string($row, 'customer_name'),
                Row::string($row, 'customer_phone'),
                Row::nullableString($row, 'customer_email'),
            ),
            BookingStatus::from(Row::string($row, 'status')),
            Row::string($row, 'source'),
            Row::date($row, 'event_date'),
            Row::string($row, 'location'),
            Row::int($row, 'guest_count'),
            $start !== null && $end !== null ? new TimeWindow($start, $end) : null,
            Row::int($row, 'subtotal'),
            Row::int($row, 'discount_amount'),
            Row::nullableString($row, 'discount_reason'),
            Row::int($row, 'total'),
            Row::int($row, 'deposit_percent'),
            Row::nullableString($row, 'notes'),
            Row::nullableString($row, 'cancel_reason'),
            Row::date($row, 'created_at'),
        );
    }

    public function paymentSchedule(int $paidNet, int $balanceDueDaysBeforeEvent): PaymentSchedule
    {
        return new PaymentSchedule(
            $this->total,
            $this->depositPercent,
            $this->eventDate,
            $balanceDueDaysBeforeEvent,
            $paidNet,
        );
    }

    /**
     * The earliest moment the booking may be marked completed: the day after the
     * event, or the end of teardown if that is later.
     */
    public function completableFrom(): DateTimeImmutable
    {
        $dayAfter = $this->eventDate->setTime(0, 0)->modify('+1 day');
        if ($this->window !== null && $this->window->end > $dayAfter) {
            return $this->window->end;
        }

        return $dayAfter;
    }

    /**
     * Snapshot of the fields that matter for audit before/after comparisons.
     *
     * @return array<string, scalar|null>
     */
    public function auditState(): array
    {
        return [
            'status' => $this->status->value,
            'event_date' => $this->eventDate->format('Y-m-d'),
            'location' => $this->location,
            'guest_count' => $this->guestCount,
            'setup_start' => $this->window?->start->format('Y-m-d H:i'),
            'teardown_end' => $this->window?->end->format('Y-m-d H:i'),
            'subtotal' => $this->subtotal,
            'discount' => $this->discountAmount,
            'discount_reason' => $this->discountReason,
            'total' => $this->total,
        ];
    }
}
