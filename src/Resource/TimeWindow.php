<?php

declare(strict_types=1);

namespace App\Resource;

use App\Support\DomainRuleViolation;
use DateTimeImmutable;

/**
 * Half-open interval [start, end): a window ending at 12:00 and one starting
 * at 12:00 touch but do not overlap.
 */
final class TimeWindow
{
    public const MAX_HOURS = 96;

    public function __construct(public readonly DateTimeImmutable $start, public readonly DateTimeImmutable $end)
    {
        if ($end <= $start) {
            throw new DomainRuleViolation('Teardown must end after setup starts.');
        }
        if ($end->getTimestamp() - $start->getTimestamp() > self::MAX_HOURS * 3600) {
            throw new DomainRuleViolation('A booking window cannot be longer than ' . self::MAX_HOURS . ' hours.');
        }
    }

    public function overlaps(self $other, int $bufferMinutes = 0): bool
    {
        $buffer = $bufferMinutes * 60;

        return $this->start->getTimestamp() < $other->end->getTimestamp() + $buffer
            && $other->start->getTimestamp() < $this->end->getTimestamp() + $buffer;
    }

    public function covers(DateTimeImmutable $dayStart): bool
    {
        $dayEnd = $dayStart->modify('+1 day');

        return $this->start < $dayEnd && $this->end > $dayStart;
    }

    public function format(): string
    {
        if ($this->start->format('Y-m-d') === $this->end->format('Y-m-d')) {
            return $this->start->format('D j M, H:i') . '–' . $this->end->format('H:i');
        }

        return $this->start->format('D j M H:i') . ' – ' . $this->end->format('D j M H:i');
    }
}
