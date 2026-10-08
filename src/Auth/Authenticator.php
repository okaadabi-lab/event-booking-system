<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\RateLimiter;

/**
 * Verifies credentials with a per email+IP failure limit. While the limit is hit,
 * even a correct password is refused, so the limit cannot be probed.
 */
final class Authenticator
{
    private const BUCKET = 'login';

    // Verified against when the email is unknown, so response time does not reveal which emails exist.
    private const DUMMY_HASH = '$2y$10$.W6xAhHyvcFbXJYv99vC3uqrkkFf/HfMni/gY95Se/UN5j9PjlsNy';

    public function __construct(
        private readonly UserRepository $users,
        private readonly RateLimiter $limiter,
        private readonly int $maxFailures,
        private readonly int $windowMinutes,
    ) {
    }

    /**
     * @throws LoginThrottled
     */
    public function attempt(string $email, string $password, string $ip): ?User
    {
        $key = mb_strtolower(trim($email)) . '|' . $ip;
        $window = $this->windowMinutes * 60;

        if ($this->limiter->tooManyAttempts(self::BUCKET, $key, $this->maxFailures, $window)) {
            throw new LoginThrottled($this->limiter->retryAfter(self::BUCKET, $key, $window));
        }

        $record = $this->users->findWithHashByEmail(mb_strtolower(trim($email)));
        $hash = $record['password_hash'] ?? self::DUMMY_HASH;
        $valid = password_verify($password, $hash);

        if ($record === null || !$valid || !$record['user']->isActive) {
            $this->limiter->hit(self::BUCKET, $key);

            return null;
        }

        $this->limiter->clear(self::BUCKET, $key);
        $this->users->rehashIfNeeded($record['user']->id, $password, $hash);

        return $record['user'];
    }
}
