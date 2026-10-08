<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\DomainRuleViolation;

final class LoginThrottled extends DomainRuleViolation
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));
        parent::__construct(
            "Too many failed sign-in attempts. Try again in $minutes " . ($minutes === 1 ? 'minute.' : 'minutes.')
        );
    }
}
