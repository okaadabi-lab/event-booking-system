<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * A business rule was broken. The message is written for the person using the app
 * and is safe to show in a flash message.
 */
class DomainRuleViolation extends RuntimeException
{
}
