<?php

declare(strict_types=1);

namespace App\Operations;

use App\Booking\Booking;
use App\Booking\BookingStatus;
use App\Payment\PaymentSchedule;
use App\Resource\Assignment;
use App\Resource\ResourceType;
use DateTimeImmutable;

/**
 * Flags that tell the operations team what still blocks an upcoming event.
 */
final class Readiness
{
    /**
     * @param list<Assignment> $assignments
     * @return list<array{level: 'risk'|'warn', label: string}>
     */
    public static function flags(
        Booking $booking,
        PaymentSchedule $schedule,
        array $assignments,
        int $conflictCount,
        DateTimeImmutable $today,
    ): array {
        $flags = [];
        if ($booking->status === BookingStatus::Inquiry) {
            $flags[] = ['level' => 'warn', 'label' => 'Not quoted yet'];
        }
        if ($booking->status !== BookingStatus::Inquiry && !$schedule->depositPaid()) {
            $flags[] = ['level' => 'risk', 'label' => 'Deposit missing'];
        }
        if ($booking->status !== BookingStatus::Inquiry && $schedule->isOverdue($today)) {
            $flags[] = ['level' => 'risk', 'label' => 'Balance overdue'];
        }
        $types = array_map(static fn (Assignment $a): ResourceType => $a->resource->type, $assignments);
        if (!in_array(ResourceType::Crew, $types, true)) {
            $flags[] = ['level' => 'risk', 'label' => 'No crew assigned'];
        }
        if ($conflictCount > 0) {
            $flags[] = ['level' => 'risk', 'label' => 'Resource conflict'];
        }
        if ($booking->window === null) {
            $flags[] = ['level' => 'warn', 'label' => 'No setup time'];
        }

        return $flags;
    }
}
