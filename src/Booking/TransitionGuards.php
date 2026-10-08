<?php

declare(strict_types=1);

namespace App\Booking;

use App\Payment\PaymentSchedule;
use DateTimeImmutable;

/**
 * Each method returns the list of unmet requirements (empty = ready). The same
 * lists drive the service checks and the checklist shown on the booking page.
 */
final class TransitionGuards
{
    /**
     * @return list<string>
     */
    public static function forQuote(bool $hasPackage, bool $hasSchedule): array
    {
        $blockers = [];
        if (!$hasPackage) {
            $blockers[] = 'add a package to the quote';
        }
        if (!$hasSchedule) {
            $blockers[] = 'set the setup and teardown times';
        }

        return $blockers;
    }

    /**
     * @return list<string>
     */
    public static function forConfirmation(PaymentSchedule $schedule, int $assignedResources, int $conflicts): array
    {
        $blockers = [];
        if (!$schedule->depositPaid()) {
            $blockers[] = $schedule->total === 0
                ? 'the quote has no total yet'
                : 'the deposit has not been paid in full';
        }
        if ($assignedResources === 0) {
            $blockers[] = 'assign at least one venue or crew';
        }
        if ($conflicts > 0) {
            $blockers[] = 'resolve the resource scheduling conflicts';
        }

        return $blockers;
    }

    /**
     * @return list<string>
     */
    public static function forCompletion(DateTimeImmutable $completableFrom, DateTimeImmutable $now): array
    {
        if ($now < $completableFrom) {
            return ['the event has not finished (teardown ends ' . $completableFrom->format('j M Y H:i') . ')'];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public static function forCancellation(string $reason): array
    {
        return mb_strlen(trim($reason)) < 5 ? ['give a cancellation reason (at least 5 characters)'] : [];
    }
}
