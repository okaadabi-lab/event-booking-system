<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Database;
use App\Support\RateLimiter;
use Tests\Support\DatabaseTestCase;
use Tests\Support\TestConfig;

final class RateLimiterTest extends DatabaseTestCase
{
    public function testAttemptIsRefusedOnceTheLimitIsExceeded(): void
    {
        $limiter = $this->container->make(RateLimiter::class);

        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = $limiter->attempt('login', 'a@example.com|10.0.0.1', 3, 900);
        }

        self::assertSame([true, true, true, false], $results);
    }

    public function testAttemptCountsHitsCommittedByOtherConnections(): void
    {
        // Another worker records a hit between this request's pre-check and its own
        // hit. Counting after writing means this request sees it and is refused.
        $limiter = $this->container->make(RateLimiter::class);
        $other = new RateLimiter(Database::fromConfig(TestConfig::get()), $this->clock);

        self::assertTrue($limiter->attempt('inquiry', '10.0.0.2', 2, 3600));
        $other->hit('inquiry', '10.0.0.2');

        self::assertFalse($limiter->attempt('inquiry', '10.0.0.2', 2, 3600));
    }

    public function testHitsOutsideTheWindowDoNotCount(): void
    {
        $limiter = $this->container->make(RateLimiter::class);
        $limiter->attempt('login', 'k', 1, 900);

        $this->clock->set($this->clock->now()->modify('+16 minutes'));

        self::assertTrue($limiter->attempt('login', 'k', 1, 900));
    }
}
