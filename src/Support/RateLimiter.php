<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Sliding-window counter stored in MySQL, so limits hold across PHP workers.
 * Keys are hashed before storage; the table never holds raw emails or IPs.
 */
final class RateLimiter
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function attempts(string $bucket, string $key, int $windowSeconds): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM rate_limit_hits
             WHERE bucket = :bucket AND key_hash = :key_hash AND hit_at > :since',
            [
                'bucket' => $bucket,
                'key_hash' => self::hash($key),
                'since' => $this->clock->now()->modify("-$windowSeconds seconds")->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function tooManyAttempts(string $bucket, string $key, int $maxAttempts, int $windowSeconds): bool
    {
        return $this->attempts($bucket, $key, $windowSeconds) >= $maxAttempts;
    }

    /**
     * Seconds until the oldest hit inside the window expires.
     */
    public function retryAfter(string $bucket, string $key, int $windowSeconds): int
    {
        $now = $this->clock->now();
        $oldest = $this->db->fetchValue(
            'SELECT MIN(hit_at) FROM rate_limit_hits
             WHERE bucket = :bucket AND key_hash = :key_hash AND hit_at > :since',
            [
                'bucket' => $bucket,
                'key_hash' => self::hash($key),
                'since' => $now->modify("-$windowSeconds seconds")->format('Y-m-d H:i:s'),
            ]
        );
        if (!is_string($oldest)) {
            return 0;
        }
        $expires = (new \DateTimeImmutable($oldest, $now->getTimezone()))->getTimestamp() + $windowSeconds;

        return max(1, $expires - $now->getTimestamp());
    }

    /**
     * Records an attempt, then reports whether it is within the limit. Writing the
     * row before counting closes the check-then-write gap: when parallel requests
     * race, only the first $maxAttempts rows to commit are allowed through.
     */
    public function attempt(string $bucket, string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $this->hit($bucket, $key);

        return $this->attempts($bucket, $key, $windowSeconds) <= $maxAttempts;
    }

    public function hit(string $bucket, string $key): void
    {
        $this->db->run(
            'INSERT INTO rate_limit_hits (bucket, key_hash, hit_at) VALUES (:bucket, :key_hash, :hit_at)',
            [
                'bucket' => $bucket,
                'key_hash' => self::hash($key),
                'hit_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function clear(string $bucket, string $key): void
    {
        $this->db->run(
            'DELETE FROM rate_limit_hits WHERE bucket = :bucket AND key_hash = :key_hash',
            ['bucket' => $bucket, 'key_hash' => self::hash($key)]
        );
    }

    public function prune(int $olderThanSeconds): void
    {
        $this->db->run(
            'DELETE FROM rate_limit_hits WHERE hit_at < :before',
            ['before' => $this->clock->now()->modify("-$olderThanSeconds seconds")->format('Y-m-d H:i:s')]
        );
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
