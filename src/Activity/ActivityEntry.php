<?php

declare(strict_types=1);

namespace App\Activity;

use DateTimeImmutable;

final class ActivityEntry
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function __construct(
        public readonly string $action,
        public readonly string $summary,
        public readonly ?string $actorName,
        public readonly DateTimeImmutable $at,
        public readonly ?array $before,
        public readonly ?array $after,
    ) {
    }

    /**
     * Fields that differ between before and after, for the "what changed" detail line.
     *
     * @return list<array{field: string, from: string, to: string}>
     */
    public function changes(): array
    {
        if ($this->before === null || $this->after === null) {
            return [];
        }
        $changes = [];
        foreach ($this->after as $field => $to) {
            $from = $this->before[$field] ?? null;
            if ($from !== $to && is_scalar($to) && ($from === null || is_scalar($from))) {
                $changes[] = ['field' => $field, 'from' => (string) $from, 'to' => (string) $to];
            }
        }

        return $changes;
    }
}
