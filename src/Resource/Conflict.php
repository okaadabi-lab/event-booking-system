<?php

declare(strict_types=1);

namespace App\Resource;

final class Conflict
{
    public function __construct(
        public readonly int $resourceId,
        public readonly string $resourceName,
        public readonly int $bookingId,
        public readonly string $bookingCode,
        public readonly string $customerName,
        public readonly TimeWindow $window,
    ) {
    }
}
