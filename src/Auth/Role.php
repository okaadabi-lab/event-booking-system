<?php

declare(strict_types=1);

namespace App\Auth;

enum Role: string
{
    case Owner = 'owner';
    case Sales = 'sales';
    case Finance = 'finance';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Sales => 'Sales',
            self::Finance => 'Finance',
        };
    }
}
