<?php

declare(strict_types=1);

namespace App\Booking;

final class QuoteTotals
{
    public function __construct(
        public readonly int $subtotal,
        public readonly int $discount,
        public readonly int $total,
    ) {
    }
}
