<?php

declare(strict_types=1);

namespace App\Booking;

use App\Support\DomainRuleViolation;
use App\Support\Money;

final class QuoteLine
{
    public const MAX_QUANTITY = 1000;

    public function __construct(
        public readonly string $itemType,
        public readonly int $catalogId,
        public readonly string $name,
        public readonly int $catalogPrice,
        public readonly int $unitPrice,
        public readonly int $quantity,
    ) {
        if (!in_array($itemType, ['package', 'addon'], true)) {
            throw new DomainRuleViolation("Unknown quote item type \"$itemType\".");
        }
        if ($unitPrice < 0 || $unitPrice > Money::MAX_AMOUNT || $catalogPrice < 0) {
            throw new DomainRuleViolation("The price for $name is out of range.");
        }
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new DomainRuleViolation("The quantity for $name must be between 1 and " . self::MAX_QUANTITY . '.');
        }
    }

    public function lineTotal(): int
    {
        return $this->unitPrice * $this->quantity;
    }

    public function isPriceOverridden(): bool
    {
        return $this->unitPrice !== $this->catalogPrice;
    }

    public function key(): string
    {
        return $this->itemType . ':' . $this->catalogId;
    }
}
