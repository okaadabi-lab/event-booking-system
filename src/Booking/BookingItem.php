<?php

declare(strict_types=1);

namespace App\Booking;

use App\Support\Row;

final class BookingItem
{
    public function __construct(
        public readonly string $itemType,
        public readonly int $catalogId,
        public readonly string $name,
        public readonly int $catalogPrice,
        public readonly int $unitPrice,
        public readonly int $quantity,
        public readonly int $lineTotal,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $type = Row::string($row, 'item_type');

        return new self(
            $type,
            $type === 'package' ? Row::int($row, 'package_id') : Row::int($row, 'addon_id'),
            Row::string($row, 'name'),
            Row::int($row, 'catalog_price'),
            Row::int($row, 'unit_price'),
            Row::int($row, 'quantity'),
            Row::int($row, 'line_total'),
        );
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
