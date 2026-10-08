<?php

declare(strict_types=1);

namespace App\Support;

/**
 * @template T
 */
final class Paginated
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pageCount();
    }

    public function firstItemNumber(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function lastItemNumber(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }
}
