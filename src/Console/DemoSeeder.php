<?php

declare(strict_types=1);

namespace App\Console;

use App\Auth\Role;
use App\Auth\User;
use App\Auth\UserRepository;
use App\Booking\BookingRepository;
use App\Booking\BookingService;
use App\Booking\QuoteRequest;
use App\Catalog\CatalogRepository;
use App\Inquiry\InquiryForm;
use App\Inquiry\InquiryService;
use App\Payment\PaymentKind;
use App\Payment\PaymentMethod;
use App\Payment\PaymentService;
use App\Resource\ResourceRepository;
use App\Resource\ResourceScheduler;
use App\Resource\ResourceType;
use App\Resource\TimeWindow;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Container;
use App\Support\Database;
use App\Support\FixedClock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Loads fictional demo data relative to today. History is written through the
 * real services with a clock that is moved back in time, so every booking has a
 * genuine activity log and passed the same rules as one entered by hand.
 */
final class DemoSeeder
{
    public const PASSWORD = 'lentera-demo-2026';

    private const TABLES = [
        'activity_log', 'payments', 'booking_resources', 'booking_items', 'bookings', 'booking_sequences',
        'customers', 'resources', 'addons', 'packages', 'sessions', 'rate_limit_hits', 'users',
    ];

    private const CUSTOMERS = [
        ['Ayu Pratiwi', 'ayu.pratiwi@example.com'], ['Bagus Santoso', null], ['Citra Lestari', 'citra.l@example.com'],
        ['Dewi Anggraini', 'dewi.a@example.com'], ['Eko Nugroho', null], ['Fajar Ramadhan', 'fajar.r@example.com'],
        ['Gita Maharani', 'gita.m@example.com'], ['Hendra Wijaya', null], ['Indah Permata', 'indah.p@example.com'],
        ['Joko Susilo', null], ['Kartika Sari', 'kartika.s@example.com'], ['Lukman Hakim', 'lukman.h@example.com'],
        ['Maya Kusuma', 'maya.k@example.com'], ['Nanda Putri', null], ['Oki Firmansyah', 'oki.f@example.com'],
        ['Putri Handayani', 'putri.h@example.com'], ['Rizky Aditya', null], ['Siti Rahmawati', 'siti.r@example.com'],
        ['Taufik Hidayat', 'taufik.h@example.com'], ['Utami Dewanti', null], ['Vina Oktaviani', 'vina.o@example.com'],
        ['Wulan Sari', 'wulan.s@example.com'], ['Yoga Pranata', null], ['Zahra Amalia', 'zahra.a@example.com'],
        ['PT Arunika Kreasi', 'events@arunika.example.com'], ['Yayasan Cahaya Bangsa', 'info@cahayabangsa.example.com'],
        ['Andi Kurniawan', 'andi.k@example.com'], ['Melati Hasanah', null], ['Galih Permana', 'galih.p@example.com'],
        ['Nadia Safitri', 'nadia.s@example.com'],
    ];

    private const LOCATIONS = [
        'Pendopo Hall, Jl. Melati Raya 12, Bekasi',
        'Garden Terrace, Jl. Melati Raya 12, Bekasi',
        'Grand Ballroom, Jl. Melati Raya 12, Bekasi',
        "Customer's home, Perumahan Taman Kenari Blok C4, Bekasi",
        'Gedung Serbaguna Kecamatan, Jl. Cempaka 8, Bekasi',
        'Rooftop venue, Menara Nusa Lt. 21, Jakarta Timur',
    ];

    private Container $container;
    private FixedClock $clock;
    private DateTimeImmutable $today;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, int> */
    private array $packages = [];

    /** @var array<string, int> */
    private array $addons = [];

    /** @var array<string, int> */
    private array $resources = [];

    private function __construct(Config $config)
    {
        $tz = new DateTimeZone($config->timezone);
        $this->today = (new DateTimeImmutable('now', $tz))->setTime(0, 0);
        $this->clock = new FixedClock($this->today->modify('-120 days'));
        $this->container = (require $config->rootDir . '/config/container.php')($config);
        $clock = $this->clock;
        $this->container->set(Clock::class, static fn (): Clock => $clock);
    }

    public static function run(Config $config): string
    {
        $seeder = new self($config);
        $seeder->wipe();
        $seeder->seedStaff();
        $seeder->seedCatalog();
        $count = $seeder->seedBookings();

        $lines = ["Loaded demo data into {$config->dbName}: $count bookings.", '', 'Sign in with any of:'];
        foreach ($seeder->users as $user) {
            $lines[] = sprintf('  %-8s %s', $user->role->value, $user->email);
        }
        $lines[] = 'Password for all demo accounts: ' . self::PASSWORD;

        return implode("\n", $lines) . "\n";
    }

    private function wipe(): void
    {
        $db = $this->container->make(Database::class);
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLES as $table) {
            $db->pdo()->exec("TRUNCATE TABLE $table");
        }
        $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function seedStaff(): void
    {
        $repo = $this->container->make(UserRepository::class);
        $staff = [
            'owner' => ['Rina Kartikasari', 'owner@lentera.example.com', Role::Owner],
            'sales' => ['Dimas Prasetyo', 'sales@lentera.example.com', Role::Sales],
            'finance' => ['Sari Wulandari', 'finance@lentera.example.com', Role::Finance],
        ];
        foreach ($staff as $key => [$name, $email, $role]) {
            $id = $repo->create($name, $email, $role, self::PASSWORD);
            $user = $repo->find($id);
            assert($user !== null);
            $this->users[$key] = $user;
        }
        $repo->create('Agus Setiawan', 'agus@lentera.example.com', Role::Sales, self::PASSWORD);
    }

    private function seedCatalog(): void
    {
        $catalog = $this->container->make(CatalogRepository::class);
        $packages = [
            'akad' => ['Akad Nikah Intimate', 'Akad setup for up to 100 guests: backdrop, aisle, family seating.', 18_500_000],
            'classic' => ['Resepsi Classic', 'Reception decor for up to 300 guests: stage, aisle and entrance styling.', 42_000_000],
            'grand' => ['Resepsi Grand', 'Full ballroom reception for up to 800 guests with ceiling installation.', 78_000_000],
            'lamaran' => ['Lamaran Garden', 'Engagement (lamaran) styling for up to 80 guests, garden or living room.', 12_500_000],
            'birthday' => ['Birthday Celebration', 'Themed backdrop, cake table and balloon styling for up to 60 guests.', 9_000_000],
            'corporate' => ['Corporate Gala', 'Stage, branded backdrop and table styling for company events.', 55_000_000],
        ];
        foreach ($packages as $key => [$name, $description, $price]) {
            $this->packages[$key] = $catalog->createPackage($name, $description, $price);
        }
        $old = $catalog->createPackage('Resepsi Rustic 2025', 'Retired package kept for older quotes.', 36_000_000);
        $catalog->updatePackage($old, 'Resepsi Rustic 2025', 'Retired package kept for older quotes.', 36_000_000, false);

        $addons = [
            'flowers' => ['Fresh flower upgrade', 'arrangement', 750_000],
            'photobooth' => ['Photo booth backdrop', 'set', 3_500_000],
            'lighting' => ['Extra LED lighting', 'set', 2_250_000],
            'gate' => ['Welcome gate arch', 'unit', 4_000_000],
            'chairs' => ['Tiffany chairs', '10 chairs', 450_000],
            'carpet' => ['Red carpet aisle', '10 m', 600_000],
            'fog' => ['Low fog for first dance', 'session', 1_800_000],
        ];
        foreach ($addons as $key => [$name, $unit, $price]) {
            $this->addons[$key] = $catalog->createAddon($name, $unit, $price);
        }

        $resources = $this->container->make(ResourceRepository::class);
        $list = [
            'pendopo' => ['Pendopo Hall', ResourceType::Venue, 300],
            'garden' => ['Garden Terrace', ResourceType::Venue, 150],
            'ballroom' => ['Grand Ballroom', ResourceType::Venue, 800],
            'melati' => ['Crew Melati', ResourceType::Crew, 8],
            'kenanga' => ['Crew Kenanga', ResourceType::Crew, 6],
            'anggrek' => ['Crew Anggrek', ResourceType::Crew, 10],
            'cempaka' => ['Crew Cempaka', ResourceType::Crew, 5],
        ];
        foreach ($list as $key => [$name, $type, $capacity]) {
            $this->resources[$key] = $resources->create($name, $type, $capacity);
        }
    }

    /**
     * Each spec: [event offset in days, package, add-ons, guests, venue|null, crew|null,
     *   final state, paid fraction of total, setup hour, teardown hour, location index].
     * States: inquiry, quoted, confirmed, completed, cancelled.
     *
     * @return list<array{int, string, array<string, int>, int, ?string, ?string, string, float, int, int, int}>
     */
    private static function bookingSpecs(): array
    {
        return [
            // Completed events in the past.
            [-78, 'classic', ['flowers' => 6, 'chairs' => 4], 280, 'pendopo', 'melati', 'completed', 1.0, 6, 23, 0],
            [-71, 'lamaran', ['flowers' => 2], 70, null, 'kenanga', 'completed', 1.0, 7, 15, 3],
            [-64, 'grand', ['lighting' => 2, 'gate' => 1, 'fog' => 1], 750, 'ballroom', 'anggrek', 'completed', 1.0, 5, 23, 2],
            [-57, 'birthday', [], 55, 'garden', 'cempaka', 'completed', 1.0, 9, 18, 1],
            [-50, 'akad', ['carpet' => 2], 90, 'garden', 'melati', 'completed', 1.0, 5, 13, 1],
            [-43, 'corporate', ['lighting' => 3, 'photobooth' => 1], 400, 'ballroom', 'anggrek', 'completed', 1.0, 8, 22, 2],
            [-36, 'classic', ['photobooth' => 1, 'chairs' => 6], 260, 'pendopo', 'kenanga', 'completed', 1.0, 6, 23, 0],
            [-29, 'lamaran', [], 60, null, 'cempaka', 'completed', 1.0, 8, 14, 3],
            [-22, 'classic', ['flowers' => 4, 'gate' => 1], 300, 'pendopo', 'melati', 'completed', 1.0, 6, 23, 0],
            [-15, 'grand', ['lighting' => 2, 'fog' => 1, 'flowers' => 10], 800, 'ballroom', 'anggrek', 'completed', 0.85, 5, 23, 2],
            [-8, 'birthday', ['photobooth' => 1], 45, 'garden', 'kenanga', 'completed', 1.0, 10, 19, 1],
            [-4, 'akad', [], 80, 'pendopo', 'cempaka', 'confirmed', 1.0, 5, 12, 0],
            [-36, 'corporate', [], 250, 'ballroom', null, 'cancelled', 0.0, 8, 22, 2],
            [-12, 'classic', ['chairs' => 3], 240, 'pendopo', 'melati', 'cancelled', 0.3, 6, 23, 0],
            // This week and next: the operations timeline.
            [0, 'classic', ['flowers' => 5, 'lighting' => 1], 290, 'pendopo', 'melati', 'confirmed', 1.0, 6, 23, 0],
            [0, 'lamaran', ['flowers' => 1], 50, null, 'kenanga', 'confirmed', 0.6, 8, 15, 3],
            [1, 'birthday', ['photobooth' => 1], 40, 'garden', null, 'confirmed', 1.0, 9, 19, 1],
            [2, 'grand', ['gate' => 1, 'lighting' => 2, 'fog' => 1], 700, 'ballroom', 'anggrek', 'confirmed', 1.0, 5, 23, 2],
            [3, 'akad', ['carpet' => 2], 100, 'garden', 'cempaka', 'confirmed', 1.0, 5, 13, 1],
            [20, 'corporate', ['photobooth' => 1], 180, null, null, 'quoted', 0.3, 9, 21, 5],
            [5, 'classic', ['chairs' => 5], 260, 'pendopo', 'kenanga', 'confirmed', 0.5, 6, 23, 0],
            [6, 'lamaran', [], 70, null, null, 'inquiry', 0.0, 8, 15, 3],
            [9, 'classic', ['flowers' => 3, 'photobooth' => 1], 300, 'pendopo', 'melati', 'confirmed', 1.0, 6, 23, 0],
            [10, 'birthday', [], 35, 'garden', 'cempaka', 'confirmed', 1.0, 10, 18, 1],
            [12, 'grand', ['lighting' => 1], 650, 'ballroom', 'anggrek', 'quoted', 0.0, 5, 23, 2],
            // Further out: deposits and quotes in progress.
            [17, 'akad', ['flowers' => 2], 90, 'garden', 'kenanga', 'confirmed', 0.3, 5, 13, 1],
            [19, 'classic', ['gate' => 1, 'chairs' => 4], 280, 'pendopo', 'melati', 'quoted', 0.1, 6, 23, 0],
            [23, 'corporate', ['lighting' => 3], 320, 'ballroom', 'anggrek', 'quoted', 0.15, 8, 22, 2],
            [26, 'lamaran', ['flowers' => 2], 60, null, 'cempaka', 'quoted', 0.0, 8, 14, 3],
            [30, 'grand', ['fog' => 1, 'gate' => 1, 'photobooth' => 1], 780, 'ballroom', 'anggrek', 'confirmed', 0.3, 5, 23, 2],
            [33, 'classic', [], 220, 'pendopo', 'kenanga', 'quoted', 0.0, 6, 23, 0],
            [15, 'birthday', ['photobooth' => 1], 50, 'garden', 'cempaka', 'inquiry', 0.0, 10, 19, 1],
            [44, 'akad', ['carpet' => 1], 85, 'pendopo', 'melati', 'confirmed', 0.3, 5, 12, 0],
            [52, 'corporate', ['photobooth' => 1, 'lighting' => 2], 400, 'ballroom', 'anggrek', 'quoted', 0.0, 8, 22, 2],
            [61, 'classic', ['flowers' => 8], 300, 'pendopo', 'kenanga', 'quoted', 0.0, 6, 23, 0],
        ];
    }

    private function seedBookings(): int
    {
        $bookings = $this->container->make(BookingService::class);
        $payments = $this->container->make(PaymentService::class);
        $repo = $this->container->make(BookingRepository::class);
        $scheduler = $this->container->make(ResourceScheduler::class);
        $resources = $this->container->make(ResourceRepository::class);
        $sales = $this->users['sales'];
        $finance = $this->users['finance'];
        $owner = $this->users['owner'];

        // Create bookings in the order they were "received" so codes follow creation date.
        $queue = [];
        foreach (self::bookingSpecs() as $i => $spec) {
            $eventDate = $this->today->modify("{$spec[0]} days");
            $leadDays = 24 + ($i * 17) % 45;
            $createdAt = $eventDate->modify("-$leadDays days")->setTime(9 + $i % 8, ($i * 13) % 60);
            if ($createdAt > $this->today->modify('-3 days')) {
                $createdAt = $this->today->modify('-' . (3 + $i % 5) . ' days')->setTime(10 + $i % 6, 15);
            }
            $queue[] = [$createdAt, $i, $spec];
        }
        usort($queue, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $count = 0;
        foreach ($queue as [$createdAt, $i, $spec]) {
            [$offset, $package, $addons, $guests, $venue, $crew, $state, $paidFraction, $setupHour, $teardownHour, $locationIndex] = $spec;
            $eventDate = $this->today->modify("$offset days");
            $this->clock->set($createdAt);

            $customerIndex = $i % count(self::CUSTOMERS);
            [$name, $email] = self::CUSTOMERS[$customerIndex];
            $customer = ['name' => $name, 'phone' => sprintf('+628120000%04d', 1000 + $customerIndex * 37), 'email' => $email];
            // Early "setup hours" in the spec mean the crew starts the evening before.
            $setupStart = $setupHour <= 6 ? $eventDate->modify('-1 day')->setTime(20, 0) : $eventDate->setTime($setupHour, 0);
            $window = new TimeWindow($setupStart, $eventDate->setTime($teardownHour, 0));

            $resourceIds = [];
            foreach ([$venue, $crew] as $key) {
                if ($key === null) {
                    continue;
                }
                $resource = $resources->find($this->resources[$key]);
                assert($resource !== null);
                if ($scheduler->findConflicts($resource, $window) === []) {
                    $resourceIds[] = $resource->id;
                }
            }

            $id = $bookings->createQuote($customer, new QuoteRequest(
                $eventDate,
                self::LOCATIONS[$locationIndex],
                $guests,
                $window,
                $this->packages[$package],
                array_combine(
                    array_map(fn (string $k): int => $this->addons[$k], array_keys($addons)),
                    array_values($addons)
                ) ?: [],
                [],
                $i % 6 === 2 ? 2_500_000 : 0,
                $i % 6 === 2 ? 'Repeat customer' : null,
                $i % 4 === 0 ? 'Family prefers white and sage palette.' : null,
                false,
                $resourceIds,
            ), $sales);
            $count++;

            if ($state === 'inquiry') {
                continue;
            }
            $this->clock->set($createdAt->modify('+1 day'));
            $bookings->markQuoted($id, $sales);

            $booking = $repo->find($id);
            assert($booking !== null);
            $toPay = (int) round($booking->total * $paidFraction);
            $deposit = $booking->paymentSchedule(0, 14)->depositAmount();
            $paidAt = min($createdAt->modify('+3 days'), $this->today->modify('-1 day'))->setTime(14, 20);
            if ($toPay > 0) {
                $first = min($toPay, $deposit);
                $this->clock->set($paidAt->modify('+1 hour'));
                $reference = 'TRF-' . (88100 + $i * 7);
                $payments->record($id, PaymentKind::Payment, $first, PaymentMethod::BankTransfer, $reference, $paidAt, $finance);
                $rest = $toPay - $first;
                $balanceAt = min(
                    $eventDate->modify('-16 days')->setTime(11, 5),
                    $this->today->modify('-1 day')->setTime(11, 5)
                );
                if ($rest > 0 && $balanceAt > $paidAt) {
                    $this->clock->set($balanceAt->modify('+1 hour'));
                    $method = $i % 3 === 0 ? PaymentMethod::Qris : PaymentMethod::BankTransfer;
                    $payments->record($id, PaymentKind::Payment, $rest, $method, 'TRF-' . (90200 + $i * 11), $balanceAt, $finance);
                }
            }

            if ($state === 'quoted') {
                continue;
            }
            if ($state === 'cancelled') {
                $this->clock->set(min($eventDate->modify('-10 days'), $this->today)->setTime(16, 40));
                $bookings->cancel($id, $toPay > 0
                    ? 'Customer moved the wedding to next year; deposit refunded minus admin fee.'
                    : 'Company postponed the event indefinitely.', $sales);
                if ($toPay > 0) {
                    $refundAt = $this->clock->now()->modify('+1 hour');
                    $this->clock->set($refundAt->modify('+5 minutes'));
                    $payments->record(
                        $id,
                        PaymentKind::Refund,
                        (int) round($toPay * 0.8),
                        PaymentMethod::BankTransfer,
                        'RFD-' . (4100 + $i),
                        $refundAt,
                        $finance,
                        'Deposit refund minus 20% admin fee'
                    );
                }
                continue;
            }

            $this->clock->set($paidAt->modify('+2 hours'));
            if ($resourceIds !== [] && $toPay >= $deposit) {
                $bookings->confirm($id, $sales);
            }
            if ($state === 'completed') {
                $this->clock->set($eventDate->modify('+1 day')->setTime(10, 0));
                $bookings->complete($id, $owner);
            }
        }

        $count += $this->seedWebsiteInquiries();
        $this->clock->set(new DateTimeImmutable('now', $this->today->getTimezone()));

        return $count;
    }

    private function seedWebsiteInquiries(): int
    {
        $inquiries = $this->container->make(InquiryService::class);
        $forms = [
            [
                'Rangga Saputra', '0812 0000 7781', 'rangga.s@example.com', 'wedding', 47,
                'Pendopo Hall or Garden Terrace', 250, 'Looking at a Javanese-modern theme with lots of white flowers.',
            ],
            [
                'Laras Wulandari', '0812-0000-7792', '', 'engagement', 21,
                'Our home in Jatiasih, Bekasi', 60, 'Do you also handle the seserahan display?',
            ],
            ['Bima Prakoso', '+62 812 0000 7803', 'bima.p@example.com', 'birthday', 15, 'Garden Terrace', 40, ''],
        ];
        $count = 0;
        foreach ($forms as $j => [$name, $phone, $email, $type, $inDays, $location, $guests, $message]) {
            $this->clock->set($this->today->modify('-' . ($j + 1) . ' days')->setTime(20 + $j, 10));
            $inquiries->submit(InquiryForm::fromInput([
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'event_type' => $type,
                'event_date' => $this->today->modify("+$inDays days")->format('Y-m-d'),
                'location' => $location,
                'guest_count' => (string) $guests,
                'message' => $message,
            ]));
            $count++;
        }

        return $count;
    }
}
