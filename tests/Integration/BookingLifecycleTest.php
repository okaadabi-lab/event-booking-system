<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\AccessDenied;
use App\Auth\Role;
use App\Auth\User;
use App\Booking\BookingRepository;
use App\Booking\BookingService;
use App\Booking\InvalidTransition;
use App\Booking\QuoteRequest;
use App\Catalog\CatalogRepository;
use App\Payment\PaymentKind;
use App\Payment\PaymentMethod;
use App\Payment\PaymentService;
use App\Resource\ResourceScheduler;
use App\Resource\ResourceType;
use App\Resource\TimeWindow;
use App\Support\DomainRuleViolation;
use App\Support\ValidationFailed;
use Tests\Support\DatabaseTestCase;

final class BookingLifecycleTest extends DatabaseTestCase
{
    private User $sales;
    private User $finance;
    private User $owner;
    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sales = $this->makeUser(Role::Sales);
        $this->finance = $this->makeUser(Role::Finance);
        $this->owner = $this->makeUser(Role::Owner);
        $this->service = $this->container->make(BookingService::class);
    }

    public function testCodesAreSequentialPerYear(): void
    {
        $first = $this->makeBooking($this->sales);
        $second = $this->makeBooking($this->sales, phone: '+6281200000002');
        $repo = $this->container->make(BookingRepository::class);

        self::assertSame('BK-2026-0001', $repo->find($first)?->code);
        self::assertSame('BK-2026-0002', $repo->find($second)?->code);
    }

    public function testTotalsAreComputedServerSideAndItemsSnapshotted(): void
    {
        $package = $this->makePackage('Resepsi Classic', 42_000_000);
        $addon = $this->makeAddon('Fresh flowers', 750_000);
        $id = $this->makeBooking($this->sales, packageId: $package, addons: [$addon => 4], discount: 1_000_000);

        $booking = $this->container->make(BookingRepository::class)->find($id);
        self::assertNotNull($booking);
        self::assertSame(45_000_000, $booking->subtotal);
        self::assertSame(44_000_000, $booking->total);
        self::assertSame(30, $booking->depositPercent);
    }

    public function testCatalogPriceChangesDoNotRepriceExistingQuotes(): void
    {
        $package = $this->makePackage('Resepsi Classic', 42_000_000);
        $id = $this->makeBooking($this->sales, packageId: $package);
        $catalog = $this->container->make(CatalogRepository::class);
        $catalog->updatePackage($package, 'Resepsi Classic', '', 50_000_000, true);

        // Re-saving the quote keeps the snapshot price even though the catalog moved.
        $this->service->updateQuote($id, $this->request($package), $this->sales);

        $items = $this->container->make(BookingRepository::class)->items($id);
        self::assertSame(42_000_000, $items[0]->unitPrice);
        self::assertSame(42_000_000, $this->container->make(BookingRepository::class)->find($id)?->total);
    }

    public function testRetiredPackageStaysOnExistingQuote(): void
    {
        $package = $this->makePackage('Rustic 2025', 36_000_000);
        $id = $this->makeBooking($this->sales, packageId: $package);
        $this->container->make(CatalogRepository::class)->updatePackage($package, 'Rustic 2025', '', 36_000_000, false);

        $this->service->updateQuote($id, $this->request($package), $this->sales);

        self::assertSame('Rustic 2025', $this->container->make(BookingRepository::class)->items($id)[0]->name);
    }

    public function testInactivePackageCannotBeAddedToANewQuote(): void
    {
        $package = $this->makePackage('Rustic 2025', 36_000_000);
        $this->container->make(CatalogRepository::class)->updatePackage($package, 'Rustic 2025', '', 36_000_000, false);

        $this->expectException(DomainRuleViolation::class);
        $this->makeBooking($this->sales, packageId: $package);
    }

    public function testSalesCannotOverrideAPrice(): void
    {
        $package = $this->makePackage('Resepsi Classic', 42_000_000);
        $id = $this->makeBooking($this->sales, packageId: $package);

        $this->expectException(AccessDenied::class);
        $this->service->updateQuote($id, $this->request($package, ["package:$package" => 30_000_000]), $this->sales);
    }

    public function testOwnerPriceOverrideIsAudited(): void
    {
        $package = $this->makePackage('Resepsi Classic', 42_000_000);
        $id = $this->makeBooking($this->sales, packageId: $package);

        $this->service->updateQuote($id, $this->request($package, ["package:$package" => 39_000_000]), $this->owner);

        self::assertSame(39_000_000, $this->container->make(BookingRepository::class)->find($id)?->total);
        $row = $this->db->fetchOne("SELECT before_state, after_state FROM activity_log WHERE action = 'price_override'");
        self::assertNotNull($row);
        self::assertStringContainsString('42000000', (string) $row['before_state']);
        self::assertStringContainsString('39000000', (string) $row['after_state']);
    }

    public function testQuoteRequiresPackageAndWindow(): void
    {
        $id = $this->makeBooking($this->sales, null, null, quoted: false);

        $this->expectException(InvalidTransition::class);
        $this->expectExceptionMessage('set the setup and teardown times');
        $this->service->markQuoted($id, $this->sales);
    }

    public function testConfirmIsBlockedUntilDepositAndResource(): void
    {
        $id = $this->makeBooking($this->sales, packageId: $this->makePackage('Akad', 20_000_000));

        try {
            $this->service->confirm($id, $this->sales);
            self::fail('Confirmation should be blocked.');
        } catch (InvalidTransition $e) {
            self::assertStringContainsString('deposit has not been paid', $e->getMessage());
            self::assertStringContainsString('assign at least one venue or crew', $e->getMessage());
        }

        $this->pay($id, 5_999_999);
        $this->container->make(ResourceScheduler::class)->assign($id, $this->makeResource('Pendopo Hall'), $this->sales->id);
        try {
            $this->service->confirm($id, $this->sales);
            self::fail('One rupiah short of the deposit should still block confirmation.');
        } catch (InvalidTransition $e) {
            self::assertStringNotContainsString('assign at least one', $e->getMessage());
        }

        $this->pay($id, 1);
        $this->service->confirm($id, $this->sales);
        self::assertSame('confirmed', $this->bookingStatus($id));
    }

    public function testConfirmIsBlockedByAConflict(): void
    {
        $hall = $this->makeResource('Pendopo Hall');
        $id = $this->makeBooking($this->sales, packageId: $this->makePackage('Akad', 20_000_000), resourceIds: [$hall]);
        $other = $this->makeBooking($this->sales, '2026-10-21 08:00', '2026-10-21 20:00', phone: '+6281200000002');
        $this->container->make(ResourceScheduler::class)->assign($other, $hall, $this->sales->id);
        // Simulate data that bypassed the scheduler (e.g. a manual SQL fix) to prove confirm re-checks.
        $this->db->run(
            "UPDATE bookings SET setup_start = '2026-10-20 08:00', teardown_end = '2026-10-21 12:00' WHERE id = :id",
            ['id' => $id]
        );
        $this->pay($id, 6_000_000);

        $this->expectExceptionMessage('resolve the resource scheduling conflicts');
        $this->service->confirm($id, $this->sales);
    }

    public function testCompleteOnlyAfterTheEvent(): void
    {
        $id = $this->confirmedBooking();

        try {
            $this->service->complete($id, $this->sales);
            self::fail('Completion before the event should fail.');
        } catch (InvalidTransition $e) {
            self::assertStringContainsString('has not finished', $e->getMessage());
        }

        $this->clock->set(self::at('2026-10-21 00:00'));
        $this->service->complete($id, $this->sales);
        self::assertSame('completed', $this->bookingStatus($id));
    }

    public function testCompletedBookingCannotBeCancelled(): void
    {
        $id = $this->confirmedBooking();
        $this->clock->set(self::at('2026-10-22 09:00'));
        $this->service->complete($id, $this->owner);

        $this->expectExceptionMessage('A completed booking cannot move to cancelled.');
        $this->service->cancel($id, 'Changed our minds', $this->owner);
    }

    public function testCancellationRecordsReasonAndAuditTrail(): void
    {
        $hall = $this->makeResource('Pendopo Hall');
        $id = $this->makeBooking($this->sales, resourceIds: [$hall]);

        $this->service->cancel($id, 'Family moved the date to next year', $this->sales);

        $booking = $this->container->make(BookingRepository::class)->find($id);
        self::assertSame('Family moved the date to next year', $booking?->cancelReason);
        $row = $this->db->fetchOne("SELECT after_state FROM activity_log WHERE action = 'status_changed' ORDER BY id DESC LIMIT 1");
        self::assertNotNull($row);
        self::assertStringContainsString('Pendopo Hall', (string) $row['after_state']);
    }

    public function testCancellationRequiresAReason(): void
    {
        $id = $this->makeBooking($this->sales);

        $this->expectException(InvalidTransition::class);
        $this->service->cancel($id, ' ', $this->sales);
    }

    public function testFinanceCannotChangeBookingStatus(): void
    {
        $id = $this->makeBooking($this->sales);

        $this->expectException(AccessDenied::class);
        $this->service->cancel($id, 'Not my job but trying', $this->finance);
    }

    public function testQuoteCannotBeEditedAfterConfirmation(): void
    {
        $id = $this->confirmedBooking();
        $package = (int) $this->db->fetchValue('SELECT package_id FROM booking_items WHERE booking_id = :id', ['id' => $id]);

        $this->expectExceptionMessage('can no longer be changed');
        $this->service->updateQuote($id, $this->request($package), $this->owner);
    }

    public function testNewQuoteCannotBeForAPastEvent(): void
    {
        self::assertSame('quoted', $this->bookingStatus($this->makeBooking($this->sales, '2026-10-09 06:00', '2026-10-09 18:00')));

        $this->expectException(ValidationFailed::class);
        $this->makeBooking($this->sales, '2026-10-08 06:00', '2026-10-08 18:00', phone: '+6281200000002');
    }

    public function testFailedSaveLeavesNoPartialBooking(): void
    {
        $hall = $this->makeResource('Pendopo Hall');
        $this->makeBooking($this->sales, resourceIds: [$hall]);
        $before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM bookings');

        try {
            $this->makeBooking($this->sales, phone: '+6281200000002', resourceIds: [$hall]);
            self::fail('Expected a conflict.');
        } catch (DomainRuleViolation) {
        }

        self::assertSame($before, (int) $this->db->fetchValue('SELECT COUNT(*) FROM bookings'));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM customers'));
    }

    public function testResourcesAreReservedInTheSameSaveAsTheQuote(): void
    {
        $hall = $this->makeResource('Pendopo Hall');
        $crew = $this->makeResource('Crew Melati', ResourceType::Crew);

        $id = $this->makeBooking($this->sales, resourceIds: [$crew, $hall]);

        self::assertCount(2, $this->container->make(ResourceScheduler::class)->assignmentsFor($id));
    }

    private function confirmedBooking(): int
    {
        $id = $this->makeBooking(
            $this->sales,
            packageId: $this->makePackage('Akad', 20_000_000),
            resourceIds: [$this->makeResource('Hall ' . bin2hex(random_bytes(2)))]
        );
        $this->pay($id, 6_000_000);
        $this->service->confirm($id, $this->sales);

        return $id;
    }

    private function pay(int $bookingId, int $amount): void
    {
        $this->container->make(PaymentService::class)->record(
            $bookingId,
            PaymentKind::Payment,
            $amount,
            PaymentMethod::BankTransfer,
            'TRF-1',
            self::at('2026-10-09 09:00'),
            $this->finance
        );
    }

    /**
     * @param array<string, int> $overrides
     */
    private function request(int $packageId, array $overrides = []): QuoteRequest
    {
        return new QuoteRequest(
            self::at('2026-10-20 00:00'),
            'Pendopo Hall',
            150,
            new TimeWindow(self::at('2026-10-20 08:00'), self::at('2026-10-20 22:00')),
            $packageId,
            [],
            $overrides,
            0,
            null,
            null,
            false,
        );
    }
}
