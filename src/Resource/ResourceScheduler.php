<?php

declare(strict_types=1);

namespace App\Resource;

use App\Activity\ActivityLog;
use App\Booking\Booking;
use App\Booking\BookingRepository;
use App\Support\Clock;
use App\Support\Database;
use App\Support\DomainRuleViolation;
use App\Support\Row;
use DateTimeImmutable;

/**
 * Assigns venues and crews to bookings without double-booking them.
 *
 * MySQL has no exclusion constraints, so the overlap rule is enforced with locks.
 * Every write that can create an overlap on a resource first locks that resource's
 * row (SELECT ... FOR UPDATE). Lock order is always: the booking row, then resource
 * rows in ascending id. A transaction never locks two bookings, so the order
 * cannot form a cycle.
 */
final class ResourceScheduler
{
    /** Bookings in these states hold their resources. */
    public const HOLDING_STATUSES = ['inquiry', 'quoted', 'confirmed'];

    public function __construct(
        private readonly Database $db,
        private readonly BookingRepository $bookings,
        private readonly ResourceRepository $resources,
        private readonly ActivityLog $activity,
        private readonly Clock $clock,
        private readonly int $bufferMinutes,
    ) {
    }

    public function bufferMinutes(): int
    {
        return $this->bufferMinutes;
    }

    public function assign(int $bookingId, int $resourceId, int $actorId): void
    {
        $this->db->transactional(function () use ($bookingId, $resourceId, $actorId): void {
            $booking = $this->bookings->lockForUpdate($bookingId) ?? throw new DomainRuleViolation('Booking not found.');
            $resource = $this->resources->lockForUpdate($resourceId)
                ?? throw new DomainRuleViolation('That resource does not exist.');

            if (!in_array($booking->status->value, self::HOLDING_STATUSES, true)) {
                throw new DomainRuleViolation("Resources cannot be assigned to a {$booking->status->value} booking.");
            }
            if ($booking->window === null) {
                throw new DomainRuleViolation('Set the setup and teardown times before assigning resources.');
            }
            if (!$resource->isActive) {
                throw new DomainRuleViolation("{$resource->name} is inactive and cannot be assigned.");
            }
            if ($this->isAssigned($bookingId, $resourceId)) {
                throw new DomainRuleViolation("{$resource->name} is already assigned to this booking.");
            }

            $conflicts = $this->findConflicts($resource, $booking->window, $bookingId);
            if ($conflicts !== []) {
                throw new ResourceConflict($resource->name, $conflicts);
            }

            $this->db->run(
                'INSERT INTO booking_resources (booking_id, resource_id, assigned_by, assigned_at)
                 VALUES (:booking_id, :resource_id, :assigned_by, :assigned_at)',
                [
                    'booking_id' => $bookingId,
                    'resource_id' => $resourceId,
                    'assigned_by' => $actorId,
                    'assigned_at' => $this->now(),
                ]
            );
            $this->activity->record(
                'booking',
                $bookingId,
                'resource_assigned',
                "Assigned {$resource->name}",
                $actorId,
                null,
                ['resource' => $resource->name, 'window' => $booking->window->format()]
            );
        });
    }

    public function release(int $bookingId, int $resourceId, int $actorId): void
    {
        $this->db->transactional(function () use ($bookingId, $resourceId, $actorId): void {
            $booking = $this->bookings->lockForUpdate($bookingId) ?? throw new DomainRuleViolation('Booking not found.');
            $resource = $this->resources->find($resourceId) ?? throw new DomainRuleViolation('That resource does not exist.');
            if (!$booking->status->isOpen()) {
                throw new DomainRuleViolation("Resources on a {$booking->status->value} booking cannot be changed.");
            }
            $released = $this->db->run(
                'UPDATE booking_resources SET released_at = :now
                 WHERE booking_id = :booking_id AND resource_id = :resource_id AND released_at IS NULL',
                ['now' => $this->now(), 'booking_id' => $bookingId, 'resource_id' => $resourceId]
            )->rowCount();
            if ($released === 0) {
                throw new DomainRuleViolation("{$resource->name} is not assigned to this booking.");
            }
            $this->activity->record(
                'booking',
                $bookingId,
                'resource_released',
                "Released {$resource->name}",
                $actorId,
                ['resource' => $resource->name],
                null
            );
        });
    }

    /**
     * Releases everything held by a booking. Called inside the cancellation transaction.
     *
     * @return list<string> names of the released resources
     */
    public function releaseAll(int $bookingId): array
    {
        $names = array_map(
            static fn (Assignment $a): string => $a->resource->name,
            $this->assignmentsFor($bookingId)
        );
        $this->db->run(
            'UPDATE booking_resources SET released_at = :now WHERE booking_id = :id AND released_at IS NULL',
            ['now' => $this->now(), 'id' => $bookingId]
        );

        return $names;
    }

    /**
     * Locks every resource the booking holds, plus any extra ids, in ascending id
     * order. The caller must already hold the booking's row lock and be inside a
     * transaction.
     *
     * @param list<int> $extraIds
     * @return list<Resource>
     */
    public function lockAssignedResources(int $bookingId, array $extraIds = []): array
    {
        $ids = array_map(
            static fn (Assignment $a): int => $a->resource->id,
            $this->assignmentsFor($bookingId)
        );
        $ids = array_values(array_unique([...$ids, ...$extraIds]));
        sort($ids);
        $locked = [];
        foreach ($ids as $id) {
            $resource = $this->resources->lockForUpdate($id);
            if ($resource !== null) {
                $locked[] = $resource;
            }
        }

        return $locked;
    }

    /**
     * Bookings already holding $resource whose window, widened by the changeover
     * buffer, overlaps $window. Windows that only touch do not conflict.
     *
     * @return list<Conflict>
     */
    public function findConflicts(Resource $resource, TimeWindow $window, ?int $excludeBookingId = null): array
    {
        $buffer = $this->bufferMinutes;
        $rows = $this->db->fetchAll(
            "SELECT b.id, b.code, b.setup_start, b.teardown_end, c.name AS customer_name
             FROM booking_resources br
             JOIN bookings b ON b.id = br.booking_id
             JOIN customers c ON c.id = b.customer_id
             WHERE br.resource_id = :resource_id
               AND br.released_at IS NULL
               AND b.status IN ('inquiry', 'quoted', 'confirmed')
               AND b.id <> :exclude_id
               AND b.setup_start < :new_end_plus_buffer
               AND b.teardown_end > :new_start_minus_buffer
             ORDER BY b.setup_start",
            [
                'resource_id' => $resource->id,
                'exclude_id' => $excludeBookingId ?? 0,
                'new_end_plus_buffer' => $window->end->modify("+$buffer minutes")->format('Y-m-d H:i:s'),
                'new_start_minus_buffer' => $window->start->modify("-$buffer minutes")->format('Y-m-d H:i:s'),
            ]
        );

        return array_map(static fn (array $row): Conflict => new Conflict(
            $resource->id,
            $resource->name,
            Row::int($row, 'id'),
            Row::string($row, 'code'),
            Row::string($row, 'customer_name'),
            new TimeWindow(Row::date($row, 'setup_start'), Row::date($row, 'teardown_end')),
        ), $rows);
    }

    /**
     * Conflicts for every resource the booking currently holds.
     *
     * @return list<Conflict>
     */
    public function conflictsForBooking(Booking $booking): array
    {
        if ($booking->window === null) {
            return [];
        }
        $conflicts = [];
        foreach ($this->assignmentsFor($booking->id) as $assignment) {
            array_push($conflicts, ...$this->findConflicts($assignment->resource, $booking->window, $booking->id));
        }

        return $conflicts;
    }

    /**
     * Availability of every active resource for a proposed window, for the quote builder.
     *
     * @return list<array{resource: Resource, conflicts: list<Conflict>}>
     */
    public function availability(TimeWindow $window, ?int $excludeBookingId): array
    {
        $result = [];
        foreach ($this->resources->all(true) as $resource) {
            $result[] = ['resource' => $resource, 'conflicts' => $this->findConflicts($resource, $window, $excludeBookingId)];
        }

        return $result;
    }

    public function isAssigned(int $bookingId, int $resourceId): bool
    {
        return $this->db->fetchValue(
            'SELECT COUNT(*) FROM booking_resources
             WHERE booking_id = :booking_id AND resource_id = :resource_id AND released_at IS NULL',
            ['booking_id' => $bookingId, 'resource_id' => $resourceId]
        ) > 0;
    }

    /**
     * @return list<Assignment>
     */
    public function assignmentsFor(int $bookingId): array
    {
        return $this->assignmentsForBookings([$bookingId])[$bookingId] ?? [];
    }

    /**
     * @param list<int> $bookingIds
     * @return array<int, list<Assignment>> booking id => assignments
     */
    public function assignmentsForBookings(array $bookingIds): array
    {
        if ($bookingIds === []) {
            return [];
        }
        [$in, $params] = Database::inList('b', $bookingIds);
        $rows = $this->db->fetchAll(
            "SELECT br.booking_id, br.assigned_at, r.*, u.name AS assigned_by_name
             FROM booking_resources br
             JOIN resources r ON r.id = br.resource_id
             LEFT JOIN users u ON u.id = br.assigned_by
             WHERE br.booking_id IN $in AND br.released_at IS NULL
             ORDER BY FIELD(r.type, 'venue', 'crew'), r.name",
            $params
        );
        $map = [];
        foreach ($rows as $row) {
            $map[Row::int($row, 'booking_id')][] = new Assignment(
                Row::int($row, 'booking_id'),
                Resource::fromRow($row),
                Row::nullableString($row, 'assigned_by_name'),
                Row::date($row, 'assigned_at'),
            );
        }

        return $map;
    }

    /**
     * Held windows per resource that touch [from, to), for the month calendar.
     * Completed bookings are included so past days show what happened.
     *
     * @return list<array{resource_id: int, booking_id: int, code: string, status: string,
     *     customer: string, window: TimeWindow}>
     */
    public function occupancy(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT br.resource_id, b.id AS booking_id, b.code, b.status, b.setup_start, b.teardown_end,
                    c.name AS customer_name
             FROM booking_resources br
             JOIN bookings b ON b.id = br.booking_id
             JOIN customers c ON c.id = b.customer_id
             WHERE br.released_at IS NULL
               AND b.status IN ('inquiry', 'quoted', 'confirmed', 'completed')
               AND b.setup_start < :to AND b.teardown_end > :from
             ORDER BY b.setup_start",
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')]
        );

        return array_map(static fn (array $row): array => [
            'resource_id' => Row::int($row, 'resource_id'),
            'booking_id' => Row::int($row, 'booking_id'),
            'code' => Row::string($row, 'code'),
            'status' => Row::string($row, 'status'),
            'customer' => Row::string($row, 'customer_name'),
            'window' => new TimeWindow(Row::date($row, 'setup_start'), Row::date($row, 'teardown_end')),
        ], $rows);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
