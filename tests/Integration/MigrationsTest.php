<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Support\Database;
use App\Support\Migrator;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestConfig;

final class MigrationsTest extends TestCase
{
    private Database $db;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->db = Database::fromConfig(TestConfig::get());
        $this->migrator = new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations');
    }

    protected function tearDown(): void
    {
        // Leave a fully migrated schema behind for the other suites.
        $this->migrator->dropAllTables();
        $this->migrator->migrate();
    }

    public function testMigrationsApplyCleanlyToAnEmptyDatabaseAndAreRecorded(): void
    {
        $this->migrator->dropAllTables();

        $applied = $this->migrator->migrate();

        self::assertSame(array_keys($this->migrator->files()), $applied);
        self::assertSame(count($applied), (int) $this->db->fetchValue('SELECT COUNT(*) FROM schema_migrations'));
        foreach (['users', 'bookings', 'booking_items', 'booking_resources', 'payments', 'activity_log'] as $table) {
            self::assertSame(1, (int) $this->db->fetchValue(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t',
                ['t' => $table]
            ), "Table $table is missing.");
        }
    }

    public function testRunningAgainIsANoOp(): void
    {
        $this->migrator->migrate();

        self::assertSame([], $this->migrator->migrate());
    }

    public function testMoneyColumnsAreIntegers(): void
    {
        $types = $this->db->fetchAll(
            "SELECT table_name AS t, column_name AS c, data_type AS type FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND column_name IN ('price', 'subtotal', 'discount_amount', 'total', 'amount', 'unit_price', 'line_total')"
        );

        self::assertNotEmpty($types);
        foreach ($types as $row) {
            self::assertSame('bigint', strtolower((string) $row['type']), "{$row['t']}.{$row['c']}");
        }
    }

    public function testCheckConstraintsGuardTotalsAndStatus(): void
    {
        $this->db->run("INSERT INTO customers (name, phone) VALUES ('X', '+620000')");

        try {
            $this->db->run(
                "INSERT INTO bookings (code, customer_id, status, source, event_date, location, guest_count,
                    subtotal, discount_amount, total, deposit_percent)
                 VALUES ('BK-T-1', 1, 'quoted', 'staff', '2026-10-20', 'X', 10, 1000, 0, 999, 30)"
            );
            self::fail('A total that is not subtotal minus discount must be rejected.');
        } catch (PDOException $e) {
            self::assertStringContainsString('chk_bookings_totals', $e->getMessage());
        }

        $this->expectException(PDOException::class);
        $this->db->run(
            "INSERT INTO bookings (code, customer_id, status, source, event_date, location, guest_count, deposit_percent)
             VALUES ('BK-T-2', 1, 'approved', 'staff', '2026-10-20', 'X', 10, 30)"
        );
    }

    public function testStatementSplitterIgnoresComments(): void
    {
        $sql = "-- a comment; with a semicolon\nCREATE TABLE a (id INT);\n\nCREATE TABLE b (id INT);\n";

        self::assertSame(['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'], Migrator::statements($sql));
    }
}
