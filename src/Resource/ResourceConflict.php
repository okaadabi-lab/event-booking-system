<?php

declare(strict_types=1);

namespace App\Resource;

use App\Support\DomainRuleViolation;

final class ResourceConflict extends DomainRuleViolation
{
    /**
     * @param list<Conflict> $conflicts
     */
    public function __construct(public readonly string $resourceName, public readonly array $conflicts)
    {
        $codes = implode(', ', array_map(static fn (Conflict $c): string => $c->bookingCode, $conflicts));
        parent::__construct("$resourceName is already booked in that window ($codes), including the changeover buffer.");
    }
}
