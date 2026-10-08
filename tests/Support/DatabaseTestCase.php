<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Auth\Role;
use App\Auth\User;
use App\Auth\UserRepository;
use App\Booking\BookingRepository;
use App\Booking\BookingService;
use App\Booking\QuoteRequest;
use App\Catalog\CatalogRepository;
use App\Resource\ResourceRepository;
use App\Resource\ResourceType;
use App\Resource\TimeWindow;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Container;
use App\Support\Database;
use App\Support\FixedClock;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Runs against the real MySQL/MariaDB test database. Every test starts from
 * empty tables and a fixed clock (Fri 9 Oct 2026, 10:00 Asia/Jakarta).
 */
abstract class DatabaseTestCase extends TestCase
{
    private const TABLES = [
        'activity_log', 'payments', 'booking_resources', 'booking_items', 'bookings', 'booking_sequences',
        'customers', 'resources', 'addons', 'packages', 'sessions', 'rate_limit_hits', 'users',
    ];

    protected Container $container;
    protected FixedClock $clock;
    protected Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FixedClock(self::at('2026-10-09 10:00'));
        $this->container = $this->buildContainer(TestConfig::get());
        $this->db = $this->container->make(Database::class);
        $this->truncateAll();
    }

    protected function buildContainer(Config $config): Container
    {
        $container = (require dirname(__DIR__, 2) . '/config/container.php')($config);
        $clock = $this->clock;
        $container->set(Clock::class, static fn (): Clock => $clock);

        return $container;
    }

    protected static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('Asia/Jakarta'));
    }

    protected function makeUser(Role $role, string $email = '', string $password = 'correct-horse-battery'): User
    {
        $repo = $this->container->make(UserRepository::class);
        $email = $email !== '' ? $email : "{$role->value}@test.example.com";
        $id = $repo->create(ucfirst($role->value) . ' Tester', $email, $role, $password);
        $user = $repo->find($id);
        self::assertNotNull($user);

        return $user;
    }

    protected function makeResource(string $name, ResourceType $type = ResourceType::Venue): int
    {
        return $this->container->make(ResourceRepository::class)->create($name, $type, 100);
    }

    protected function makePackage(string $name = 'Resepsi Classic', int $price = 40_000_000): int
    {
        return $this->container->make(CatalogRepository::class)->createPackage($name, 'Test package', $price);
    }

    protected function makeAddon(string $name = 'Fresh flowers', int $price = 750_000): int
    {
        return $this->container->make(CatalogRepository::class)->createAddon($name, 'arrangement', $price);
    }

    /**
     * Creates a staff booking through the real service.
     *
     * @param array<int, int> $addons
     * @param list<int> $resourceIds
     */
    protected function makeBooking(
        User $actor,
        ?string $start = '2026-10-20 08:00',
        ?string $end = '2026-10-20 22:00',
        ?int $packageId = null,
        array $addons = [],
        bool $quoted = true,
        array $resourceIds = [],
        string $phone = '+6281200000001',
        int $discount = 0,
    ): int {
        $customer = ['name' => 'Test Customer', 'phone' => $phone, 'email' => null];
        $packageId ??= $this->makePackage('Package ' . bin2hex(random_bytes(3)));
        $window = $start !== null && $end !== null ? new TimeWindow(self::at($start), self::at($end)) : null;
        $eventDate = $window !== null ? $window->end->setTime(0, 0) : self::at('2026-10-20 00:00');

        return $this->container->make(BookingService::class)->createQuote($customer, new QuoteRequest(
            $eventDate,
            'Pendopo Hall',
            150,
            $window,
            $packageId,
            $addons,
            [],
            $discount,
            $discount > 0 ? 'Test discount' : null,
            null,
            $quoted,
            $resourceIds,
        ), $actor);
    }

    protected function bookingStatus(int $id): string
    {
        $booking = $this->container->make(BookingRepository::class)->find($id);
        self::assertNotNull($booking);

        return $booking->status->value;
    }

    /**
     * @return list<string>
     */
    protected function activityActions(int $bookingId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT action FROM activity_log WHERE entity_type = 'booking' AND entity_id = :id ORDER BY id",
            ['id' => $bookingId]
        );

        return array_map(static fn (array $row): string => (string) $row['action'], $rows);
    }

    private function truncateAll(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $pdo->exec("TRUNCATE TABLE $table");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
