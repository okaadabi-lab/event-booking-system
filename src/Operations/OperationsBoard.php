<?php

declare(strict_types=1);

namespace App\Operations;

use App\Booking\Booking;
use App\Booking\BookingRepository;
use App\Payment\PaymentSchedule;
use App\Resource\Assignment;
use App\Resource\Resource;
use App\Resource\ResourceRepository;
use App\Resource\ResourceScheduler;
use DateTimeImmutable;

final class OperationsBoard
{
    public function __construct(
        private readonly BookingRepository $bookings,
        private readonly ResourceRepository $resources,
        private readonly ResourceScheduler $scheduler,
        private readonly int $balanceDueDaysBeforeEvent,
    ) {
    }

    /**
     * Upcoming open bookings grouped by event day, each with assignments and readiness flags.
     *
     * @return list<array{date: DateTimeImmutable, events: list<array{booking: Booking,
     *     schedule: PaymentSchedule, assignments: list<Assignment>,
     *     flags: list<array{level: string, label: string}>}>}>
     */
    public function timeline(DateTimeImmutable $today, int $days): array
    {
        $from = $today->setTime(0, 0);
        $bookings = $this->bookings->upcoming($from, $from->modify('+' . ($days - 1) . ' days'));
        $assignments = $this->scheduler->assignmentsForBookings(array_map(static fn (Booking $b): int => $b->id, $bookings));

        $groups = [];
        foreach ($bookings as $booking) {
            $schedule = $booking->paymentSchedule($this->bookings->paidNet($booking->id), $this->balanceDueDaysBeforeEvent);
            $held = $assignments[$booking->id] ?? [];
            $day = $booking->eventDate->format('Y-m-d');
            $groups[$day] ??= ['date' => $booking->eventDate, 'events' => []];
            $groups[$day]['events'][] = [
                'booking' => $booking,
                'schedule' => $schedule,
                'assignments' => $held,
                'flags' => Readiness::flags(
                    $booking,
                    $schedule,
                    $held,
                    count($this->scheduler->conflictsForBooking($booking)),
                    $today
                ),
            ];
        }

        return array_values($groups);
    }

    /**
     * Resources x days grid for one month. Each cell lists the bookings holding the
     * resource on that day, so availability is visible at a glance.
     *
     * @return array{days: list<DateTimeImmutable>, rows: list<array{resource: Resource,
     *     cells: list<list<array{booking_id: int, code: string, status: string, customer: string,
     *     starts: bool, ends: bool, time: string}>>, busy_days: int}>}
     */
    public function monthGrid(DateTimeImmutable $month): array
    {
        $first = $month->modify('first day of this month')->setTime(0, 0);
        $next = $first->modify('+1 month');
        $days = [];
        for ($d = $first; $d < $next; $d = $d->modify('+1 day')) {
            $days[] = $d;
        }

        $occupancy = [];
        foreach ($this->scheduler->occupancy($first, $next) as $hold) {
            $occupancy[$hold['resource_id']][] = $hold;
        }

        $rows = [];
        foreach ($this->resources->all(true) as $resource) {
            $cells = [];
            $busy = 0;
            foreach ($days as $day) {
                $cell = [];
                foreach ($occupancy[$resource->id] ?? [] as $hold) {
                    if (!$hold['window']->covers($day)) {
                        continue;
                    }
                    $startsToday = $hold['window']->start->format('Y-m-d') === $day->format('Y-m-d');
                    $endsToday = $hold['window']->end->format('Y-m-d') === $day->format('Y-m-d');
                    $cell[] = [
                        'booking_id' => $hold['booking_id'],
                        'code' => $hold['code'],
                        'status' => $hold['status'],
                        'customer' => $hold['customer'],
                        'starts' => $startsToday,
                        'ends' => $endsToday,
                        'time' => ($startsToday ? $hold['window']->start->format('H:i') : '…')
                            . '–' . ($endsToday ? $hold['window']->end->format('H:i') : '…'),
                    ];
                }
                $busy += $cell === [] ? 0 : 1;
                $cells[] = $cell;
            }
            $rows[] = ['resource' => $resource, 'cells' => $cells, 'busy_days' => $busy];
        }

        return ['days' => $days, 'rows' => $rows];
    }

    /**
     * Bookings with money still to collect, most urgent first.
     *
     * @return list<array{booking: Booking, schedule: PaymentSchedule, overdue: bool, days_overdue: int}>
     */
    public function receivables(DateTimeImmutable $today): array
    {
        $result = [];
        foreach ($this->bookings->receivables() as $entry) {
            $schedule = $entry['booking']->paymentSchedule($entry['paid_net'], $this->balanceDueDaysBeforeEvent);
            if ($schedule->outstanding() === 0) {
                continue;
            }
            $result[] = [
                'booking' => $entry['booking'],
                'schedule' => $schedule,
                'overdue' => $schedule->isOverdue($today),
                'days_overdue' => $schedule->daysOverdue($today),
            ];
        }
        usort($result, static function (array $a, array $b): int {
            return [$b['overdue'], $b['days_overdue'], $a['schedule']->balanceDueDate()]
                <=> [$a['overdue'], $a['days_overdue'], $b['schedule']->balanceDueDate()];
        });

        return $result;
    }
}
