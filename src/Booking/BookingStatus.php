<?php

declare(strict_types=1);

namespace App\Booking;

enum BookingStatus: string
{
    case Inquiry = 'inquiry';
    case Quoted = 'quoted';
    case Confirmed = 'confirmed';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isOpen(): bool
    {
        return $this !== self::Completed && $this !== self::Cancelled;
    }
}
