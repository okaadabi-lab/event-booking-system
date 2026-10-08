<?php

declare(strict_types=1);

namespace App\Booking;

use DateTimeImmutable;

final class BookingFilter
{
    public const SORTS = ['event_date', 'created_at', 'total', 'code', 'customer'];
    public const PER_PAGE = 15;

    public function __construct(
        public readonly ?BookingStatus $status = null,
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $to = null,
        public readonly string $search = '',
        public readonly string $sort = 'event_date',
        public readonly string $direction = 'asc',
        public readonly int $page = 1,
    ) {
    }

    /**
     * Lenient by design: unknown or malformed values fall back to defaults so a
     * hand-edited URL shows the list instead of an error.
     *
     * @param array<array-key, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $read = static fn (string $key): string => isset($query[$key]) && is_string($query[$key]) ? trim($query[$key]) : '';
        $date = static function (string $value): ?DateTimeImmutable {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
        };
        $sort = in_array($read('sort'), self::SORTS, true) ? $read('sort') : 'event_date';

        return new self(
            BookingStatus::tryFrom($read('status')),
            $date($read('from')),
            $date($read('to')),
            mb_substr($read('q'), 0, 100),
            $sort,
            $read('dir') === 'desc' ? 'desc' : 'asc',
            max(1, min(10_000, (int) $read('page'))),
        );
    }

    /**
     * @param array<string, string|int|null> $overrides
     * @return array<string, string|int>
     */
    public function toQuery(array $overrides = []): array
    {
        $query = array_merge([
            'status' => $this->status?->value,
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
            'q' => $this->search,
            'sort' => $this->sort,
            'dir' => $this->direction,
            'page' => $this->page,
        ], $overrides);

        return array_filter(
            $query,
            static fn ($value, $key): bool => $value !== null && $value !== ''
                && !($key === 'page' && (int) $value === 1)
                && !($key === 'sort' && $value === 'event_date')
                && !($key === 'dir' && $value === 'asc'),
            ARRAY_FILTER_USE_BOTH
        );
    }

    public function isFiltered(): bool
    {
        return $this->status !== null || $this->from !== null || $this->to !== null || $this->search !== '';
    }
}
