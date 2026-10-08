<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class ValidationFailed extends RuntimeException
{
    /**
     * @param array<string, string> $errors field name => message
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The submitted data is not valid.');
    }
}
