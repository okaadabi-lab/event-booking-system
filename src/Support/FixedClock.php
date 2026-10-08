<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;

/**
 * A clock that only moves when told to. Used by the demo seeder to write
 * history at realistic past times, and by tests.
 */
final class FixedClock implements Clock
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
