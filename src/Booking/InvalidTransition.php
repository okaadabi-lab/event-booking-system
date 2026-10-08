<?php

declare(strict_types=1);

namespace App\Booking;

use App\Support\DomainRuleViolation;

final class InvalidTransition extends DomainRuleViolation
{
    /**
     * @param list<string> $blockers
     */
    public static function blocked(BookingStatus $to, array $blockers): self
    {
        $verb = match ($to) {
            BookingStatus::Quoted => 'quoted',
            BookingStatus::Confirmed => 'confirmed',
            BookingStatus::Completed => 'completed',
            BookingStatus::Cancelled => 'cancelled',
            BookingStatus::Inquiry => 'reopened',
        };

        return new self("This booking cannot be $verb yet: " . implode('; ', $blockers) . '.');
    }

    public static function notAllowed(BookingStatus $from, BookingStatus $to): self
    {
        return new self(sprintf(
            'A %s booking cannot move to %s.',
            strtolower($from->label()),
            strtolower($to->label())
        ));
    }
}
