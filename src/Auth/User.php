<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\Row;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly Role $role,
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
            Row::string($row, 'email'),
            Role::from(Row::string($row, 'role')),
            Row::bool($row, 'is_active'),
        );
    }

    public function firstName(): string
    {
        return explode(' ', $this->name)[0];
    }
}
