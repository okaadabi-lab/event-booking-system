<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Support\Row;

final class CatalogItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $name,
        public readonly string $detail,
        public readonly int $price,
        public readonly bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function packageFromRow(array $row): self
    {
        return new self(
            Row::int($row, 'id'),
            'package',
            Row::string($row, 'name'),
            Row::string($row, 'description'),
            Row::int($row, 'price'),
            Row::bool($row, 'is_active'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function addonFromRow(array $row): self
    {
        return new self(
            Row::int($row, 'id'),
            'addon',
            Row::string($row, 'name'),
            Row::string($row, 'unit_label'),
            Row::int($row, 'price'),
            Row::bool($row, 'is_active'),
        );
    }
}
