<?php

declare(strict_types=1);

namespace App\Booking;

use App\Resource\TimeWindow;
use DateTimeImmutable;

/**
 * Validated quote builder input. Catalog references are resolved and priced by
 * BookingService, never trusted from the form.
 */
final class QuoteRequest
{
    /**
     * @param array<int, int> $addonQuantities addon id => quantity
     * @param array<string, int> $priceOverrides "package:ID" / "addon:ID" => unit price
     * @param list<int> $resourceIds resources to reserve when the quote is saved
     */
    public function __construct(
        public readonly DateTimeImmutable $eventDate,
        public readonly string $location,
        public readonly int $guestCount,
        public readonly ?TimeWindow $window,
        public readonly ?int $packageId,
        public readonly array $addonQuantities,
        public readonly array $priceOverrides,
        public readonly int $discount,
        public readonly ?string $discountReason,
        public readonly ?string $notes,
        public readonly bool $markQuoted,
        public readonly array $resourceIds = [],
    ) {
    }
}
