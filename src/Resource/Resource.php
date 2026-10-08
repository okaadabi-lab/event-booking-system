<?php

declare(strict_types=1);

namespace App\Resource;

use App\Support\Row;

final class Resource
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ResourceType $type,
        public readonly ?int $capacity,
        public readonly bool $isActive,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            Row::int($row, 'id'),
            Row::string($row, 'name'),
            ResourceType::from(Row::string($row, 'type')),
            Row::nullableInt($row, 'capacity'),
            Row::bool($row, 'is_active'),
        );
    }
}
