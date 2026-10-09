<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\Role;
use App\Auth\User;
use App\Booking\BookingRepository;
use App\Booking\BookingService;
use App\Booking\QuoteRequest;
use App\Resource\ResourceConflict;
use App\Resource\ResourceRepository;
use App\Resource\ResourceScheduler;
use App\Resource\ResourceType;
use App\Resource\TimeWindow;
use App\Support\Database;
use App\Support\DomainRuleViolation;
use PDOException;
use Tests\Support\DatabaseTestCase;
use Tests\Support\TestConfig;

final class ResourceConflictTest extends DatabaseTestCase
{
    private User $sales;
    private ResourceScheduler $scheduler;
    private int $hall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sales = $this->makeUser(Role::Sales);
        $this->scheduler = $this->container->make(ResourceScheduler::class);
        $this->hall = $this->makeResource('Pendopo Hall');
    }

    public function testAssignsAFreeResourceAndLogsIt(): void
    {
        $booking = $this->makeBooking($this->sales);

        $this->scheduler->assign($booking, $this->hall, $this->sales->id);

        self::assertTrue($this->scheduler->isAssigned($booking, $this->hall));
        self::assertContains('resource_assigned', $this->activityActions($booking));
    }

    public function testRejectsOverlappingWindow(): void
    {
        $first = $this->makeBooking($this->sales, '2026-10-20 08:00', '2026-10-20 22:00');
        $second = $this->makeBooking($this->sales, '2026-10-20 18:00', '2026-10-21 02:00', phone: '+6281200000002');
        $this->scheduler->assign($first, $this->hall, $this->sales->id);

        try {
            $this->scheduler->assign($second, $this->hall, $this->sales->id);
            self::fail('Expected a conflict.');
        } catch (ResourceConflict $e) {
            self::assertSame($first, $e->conflicts[0]->bookingId);
            self::assertStringContainsString('Pendopo Hall is already booked', $e->getMessage());
        }
        self::assertFalse($this->scheduler->isAssigned($second, $this->hall));
    }

    public function testWindowInsideTheBufferConflicts(): void
    {
        // Buffer is 120 minutes: a setup starting 119 minutes after teardown is too tight.
        $first = $this->makeBooking($this->sales, '2026-10-20 08:00', '2026-10-20 14:00');
        $second = $this->makeBooking($this->sales, '2026-10-20 15:59', '2026-10-20 22:00', phone: '+6281200000002');
        $this->scheduler->assign($first, $this->hall, $this->sales->id);

        $this->expectException(ResourceConflict::class);
        $this->scheduler->assign($second, $this->hall, $this->sales->id);
    }

    public function testWindowExactlyOneBufferApartIsAllowed(): void
    {
        $first = $this->makeBooking($this->sales, '2026-10-20 08:00', '2026-10-20 14:00');
        $second = $this->makeBooking($this->sales, '2026-10-20 16:00', '2026-10-20 22:00', phone: '+6281200000002');
        $this->scheduler->assign($first, $this->hall, $this->sales->id);

        $this->scheduler->assign($second, $this->hall, $this->sales->id);

        self::assertTrue($this->scheduler->isAssigned($second, $this->hall));
    }

    public function testEdgeTouchingWindowsAreAllowedWithoutBuffer(): void
    {
        $scheduler = $this->schedulerWithBuffer(0);
        $first = $this->makeBooking($this->sales, '2026-10-20 06:00', '2026-10-20 12:00');
        $second = $this->makeBooking($this->sales, '2026-10-20 12:00', '2026-10-20 18:00', phone: '+6281200000002');
        $third = $this->makeBooking($this->sales, '2026-10-20 11:59', '2026-10-20 12:30', phone: '+6281200000003');
        $scheduler->assign($first, $this->hall, $this->sales->id);

        $scheduler->assign($second, $this->hall, $this->sales->id);
        self::assertTrue($scheduler->isAssigned($second, $this->hall));

        $this->expectException(ResourceConflict::class);
        $scheduler->assign($third, $this->hall, $this->sales->id);
    }

    public function testBufferAppliesBeforeAnExistingBookingToo(): void
    {
        $later = $this->makeBooking($this->sales, '2026-10-20 16:00', '2026-10-20 22:00');
        $earlier = $this->makeBooking($this->sales, '2026-10-20 08:00', '2026-10-20 14:30', phone: '+6281200000002');
        $this->scheduler->assign($later, $this->hall, $this->sales->id);

        $this->expectException(ResourceConflict::class);
        $this->scheduler->assign($earlier, $this->hall, $this->sales->id);
    }

    public function testDifferentResourcesDoNotConflict(): void
    {
        $crew = $this->makeResource('Crew Melati', ResourceType::Crew);
        $first = $this->makeBooking($this->sales);
        $second = $this->makeBooking($this->sales, phone: '+6281200000002');
        $this->scheduler->assign($first, $this->hall, $this->sales->id);

        $this->scheduler->assign($second, $crew, $this->sales->id);

        self::assertTrue($this->scheduler->isAssigned($second, $crew));
    }

    public function testCancellingReleasesResourcesForOtherBookings(): void
    {
        $owner = $this->makeUser(Role::Owner);
        $first = $this->makeBooking($this->sales);
        $second = $this->makeBooking($this->sales, phone: '+6281200000002');
        $this->scheduler->assign($first, $this->hall, $this->sales->id);

        $this->container->make(BookingService::class)->cancel($first, 'Customer postponed the wedding', $owner);

        self::assertSame('cancelled', $this->bookingStatus($first));
        self::assertSame([], $this->scheduler->assignmentsFor($first));
        $this->scheduler->assign($second, $this->hall, $this->sales->id);
        self::assertTrue($this->scheduler->isAssigned($second, $this->hall));
        self::assertSame(
            1,
            (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM booking_resources WHERE booking_id = :id AND released_at IS NOT NULL',
                ['id' => $first]
            )
        );
    }

    public function testReleasedResourceCanBeReassignedToTheSameBooking(): void
    {
        $booking = $this->makeBooking($this->sales);
        $this->scheduler->assign($booking, $this->hall, $this->sales->id);
        $this->scheduler->release($booking, $this->hall, $this->sales->id);

        $this->scheduler->assign($booking, $this->hall, $this->sales->id);

        self::assertTrue($this->scheduler->isAssigned($booking, $this->hall));
    }

    public function testDatabaseRejectsDuplicateLiveAssignment(): void
    {
        $booking = $this->makeBooking($this->sales);
        $this->scheduler->assign($booking, $this->hall, $this->sales->id);

        $this->expectException(PDOException::class);
        $this->db->run(
            'INSERT INTO booking_resources (booking_id, resource_id, assigned_at) VALUES (:b, :r, NOW())',
            ['b' => $booking, 'r' => $this->hall]
        );
    }

    public function testCannotAssignToCompletedOrCancelledBooking(): void
    {
        $booking = $this->makeBooking($this->sales);
        $this->container->make(BookingService::class)->cancel($booking, 'Customer changed plans', $this->sales);

        $this->expectException(DomainRuleViolation::class);
        $this->scheduler->assign($booking, $this->hall, $this->sales->id);
    }

    public function testCannotAssignWithoutAWindow(): void
    {
        $booking = $this->makeBooking($this->sales, null, null, quoted: false);

        $this->expectExceptionMessage('Set the setup and teardown times');
        $this->scheduler->assign($booking, $this->hall, $this->sales->id);
    }

    public function testInactiveResourceCannotBeAssigned(): void
    {
        $this->container->make(ResourceRepository::class)->update($this->hall, 'Pendopo Hall', 100, false);
        $booking = $this->makeBooking($this->sales);

        $this->expectExceptionMessage('inactive');
        $this->scheduler->assign($booking, $this->hall, $this->sales->id);
    }

    public function testMovingAQuoteIntoAHeldSlotIsRejected(): void
    {
        $first = $this->makeBooking($this->sales, '2026-10-20 08:00', '2026-10-20 22:00');
        $second = $this->makeBooking($this->sales, '2026-10-22 08:00', '2026-10-22 22:00', phone: '+6281200000002');
        $this->scheduler->assign($first, $this->hall, $this->sales->id);
        $this->scheduler->assign($second, $this->hall, $this->sales->id);

        $this->expectException(ResourceConflict::class);
        $owner = $this->makeUser(Role::Owner);
        $request = $this->quoteRequestFor('2026-10-20 10:00', '2026-10-20 20:00');
        $this->container->make(BookingService::class)->updateQuote($second, $request, $owner);
    }

    public function testAvailabilityReportsFreeAndBusyResources(): void
    {
        $crew = $this->makeResource('Crew Melati', ResourceType::Crew);
        $booking = $this->makeBooking($this->sales);
        $this->scheduler->assign($booking, $this->hall, $this->sales->id);

        $result = $this->scheduler->availability(
            new TimeWindow(self::at('2026-10-20 12:00'), self::at('2026-10-20 23:00')),
            null
        );
        $byName = [];
        foreach ($result as $entry) {
            $byName[$entry['resource']->name] = count($entry['conflicts']);
        }

        self::assertSame(['Pendopo Hall' => 1, 'Crew Melati' => 0], $byName);
        self::assertSame($crew, $result[1]['resource']->id);
    }

    public function testResourceRowLockMakesConcurrentAssignmentsWait(): void
    {
        $booking = $this->makeBooking($this->sales);

        // A second connection holds the resource lock, as a concurrent assignment would.
        $other = Database::fromConfig(TestConfig::get());
        $other->pdo()->beginTransaction();
        $other->fetchOne('SELECT id FROM resources WHERE id = :id FOR UPDATE', ['id' => $this->hall]);

        $this->db->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->scheduler->assign($booking, $this->hall, $this->sales->id);
            self::fail('Assignment should have waited for the lock held by the other transaction.');
        } catch (PDOException $e) {
            self::assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $other->pdo()->rollBack();
            $this->db->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 50');
        }

        self::assertFalse($this->db->pdo()->inTransaction());
        self::assertFalse($this->scheduler->isAssigned($booking, $this->hall));
    }

    public function testConflictCheckSeesHoldsCommittedAfterTheTransactionStarted(): void
    {
        // createQuote and updateQuote read the catalog before they reach assign(). Under
        // REPEATABLE READ that first read would freeze the snapshot, and the overlap
        // query would miss a hold committed while this transaction waited for the lock.
        $mine = $this->makeBooking($this->sales, phone: '+6281200000001');
        $theirs = $this->makeBooking($this->sales, phone: '+6281200000002');
        $otherWorker = $this->buildContainer(TestConfig::get())->make(ResourceScheduler::class);

        $this->db->pdo()->beginTransaction();
        try {
            $this->db->fetchValue('SELECT COUNT(*) FROM booking_resources');
            $otherWorker->assign($theirs, $this->hall, $this->sales->id);

            $this->expectException(ResourceConflict::class);
            $this->scheduler->assign($mine, $this->hall, $this->sales->id);
        } finally {
            $this->db->pdo()->rollBack();
        }
    }

    public function testBookingLockDoesNotLockTheCustomer(): void
    {
        $booking = $this->makeBooking($this->sales);
        $customerId = (int) $this->db->fetchValue('SELECT customer_id FROM bookings WHERE id = :id', ['id' => $booking]);
        $other = Database::fromConfig(TestConfig::get());
        $other->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $this->db->transactional(function () use ($booking, $customerId, $other): void {
            $this->container->make(BookingRepository::class)->lockForUpdate($booking);
            $locked = $other->fetchOne('SELECT id FROM customers WHERE id = :id FOR UPDATE', ['id' => $customerId]);
            self::assertNotNull($locked);
        });
    }

    private function schedulerWithBuffer(int $minutes): ResourceScheduler
    {
        $container = $this->buildContainer(TestConfig::get()->with(['resourceBufferMinutes' => $minutes]));

        return $container->make(ResourceScheduler::class);
    }

    private function quoteRequestFor(string $start, string $end): QuoteRequest
    {
        $window = new TimeWindow(self::at($start), self::at($end));
        $packageId = (int) $this->db->fetchValue('SELECT package_id FROM booking_items LIMIT 1');

        return new QuoteRequest(
            $window->start->setTime(0, 0),
            'Pendopo Hall',
            150,
            $window,
            $packageId,
            [],
            [],
            0,
            null,
            null,
            false,
        );
    }
}
