<?php

declare(strict_types=1);

namespace App\Booking;

/**
 * inquiry -> quoted -> confirmed -> completed, and cancelled from any open state.
 * Which transitions exist is decided here; whether a particular booking is ready
 * for one is decided by TransitionGuards.
 */
final class BookingStateMachine
{
    private const TRANSITIONS = [
        'inquiry' => [BookingStatus::Quoted, BookingStatus::Cancelled],
        'quoted' => [BookingStatus::Confirmed, BookingStatus::Cancelled],
        'confirmed' => [BookingStatus::Completed, BookingStatus::Cancelled],
        'completed' => [],
        'cancelled' => [],
    ];

    public static function canTransition(BookingStatus $from, BookingStatus $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from->value], true);
    }

    /**
     * @param list<string> $blockers unmet requirements from TransitionGuards
     * @throws InvalidTransition
     */
    public static function assertTransition(BookingStatus $from, BookingStatus $to, array $blockers = []): void
    {
        if (!self::canTransition($from, $to)) {
            throw InvalidTransition::notAllowed($from, $to);
        }
        if ($blockers !== []) {
            throw InvalidTransition::blocked($to, $blockers);
        }
    }

    /**
     * @return list<BookingStatus>
     */
    public static function nextStates(BookingStatus $from): array
    {
        return self::TRANSITIONS[$from->value];
    }
}
