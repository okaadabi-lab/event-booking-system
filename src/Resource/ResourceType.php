<?php

declare(strict_types=1);

namespace App\Resource;

enum ResourceType: string
{
    case Venue = 'venue';
    case Crew = 'crew';

    public function label(): string
    {
        return match ($this) {
            self::Venue => 'Venue',
            self::Crew => 'Decoration crew',
        };
    }
}
