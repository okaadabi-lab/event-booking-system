<?php

declare(strict_types=1);

namespace App\Resource;

use DateTimeImmutable;

final class Assignment
{
    public function __construct(
        public readonly int $bookingId,
        public readonly Resource $resource,
        public readonly ?string $assignedByName,
        public readonly DateTimeImmutable $assignedAt,
    ) {
    }
}
