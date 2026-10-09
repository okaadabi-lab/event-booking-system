<?php

declare(strict_types=1);

namespace App\Booking;

use App\Support\Database;
use App\Support\Paginated;
use App\Support\Row;
use DateTimeImmutable;

final class BookingRepository
{
    private const SELECT = 'SELECT b.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email
        FROM bookings b JOIN customers c ON c.id = b.customer_id';

    public function __construct(private readonly Database $db)
    {
    }

    public function find(int $id): ?Booking
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE b.id = :id', ['id' => $id]);

        return $row === null ? null : Booking::fromRow($row);
    }

    /**
     * Re-reads the booking with an exclusive row lock held until the transaction ends.
     * Only the bookings row is locked: FOR UPDATE on the customer join would also lock
     * the customer and quietly serialise every write to that customer's other bookings.
     */
    public function lockForUpdate(int $id): ?Booking
    {
        if ($this->db->fetchValue('SELECT id FROM bookings WHERE id = :id FOR UPDATE', ['id' => $id]) === false) {
            return null;
        }

        return $this->find($id);
    }

    /**
     * Allocates the next code for the year (BK-2026-0042). The upsert takes a row
     * lock on the year's counter, so concurrent bookings never share a number.
     */
    public function nextCode(int $year): string
    {
        $this->db->run(
            'INSERT INTO booking_sequences (year, last_number) VALUES (:year, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_number = LAST_INSERT_ID(last_number + 1)',
            ['year' => $year]
        );
        $number = (int) $this->db->pdo()->lastInsertId();

        return sprintf('BK-%d-%04d', $year, $number);
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function insert(array $values): int
    {
        $columns = array_keys($values);

        return $this->db->insert(
            'INSERT INTO bookings (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')',
            $values
        );
    }

    /**
     * @param array<string, scalar|null> $values
     */
    public function update(int $id, array $values): void
    {
        $assignments = implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($values)));
        $this->db->run("UPDATE bookings SET $assignments WHERE id = :id", $values + ['id' => $id]);
    }

    /**
     * @return list<BookingItem>
     */
    public function items(int $bookingId): array
    {
        return array_map(BookingItem::fromRow(...), $this->db->fetchAll(
            "SELECT * FROM booking_items WHERE booking_id = :id ORDER BY FIELD(item_type, 'package', 'addon'), id",
            ['id' => $bookingId]
        ));
    }

    /**
     * @param list<QuoteLine> $lines
     */
    public function replaceItems(int $bookingId, array $lines): void
    {
        $this->db->run('DELETE FROM booking_items WHERE booking_id = :id', ['id' => $bookingId]);
        foreach ($lines as $line) {
            $this->db->run(
                'INSERT INTO booking_items
                    (booking_id, item_type, package_id, addon_id, name, catalog_price, unit_price, quantity, line_total)
                 VALUES (:booking_id, :item_type, :package_id, :addon_id, :name, :catalog_price, :unit_price, :quantity, :line_total)',
                [
                    'booking_id' => $bookingId,
                    'item_type' => $line->itemType,
                    'package_id' => $line->itemType === 'package' ? $line->catalogId : null,
                    'addon_id' => $line->itemType === 'addon' ? $line->catalogId : null,
                    'name' => $line->name,
                    'catalog_price' => $line->catalogPrice,
                    'unit_price' => $line->unitPrice,
                    'quantity' => $line->quantity,
                    'line_total' => $line->lineTotal(),
                ]
            );
        }
    }

    /**
     * Payments minus refunds.
     */
    public function paidNet(int $bookingId): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COALESCE(SUM(IF(kind = 'payment', amount, -CAST(amount AS SIGNED))), 0)
             FROM payments WHERE booking_id = :id",
            ['id' => $bookingId]
        );
    }

    /**
     * @return Paginated<array<string, mixed>>
     */
    public function search(BookingFilter $filter): Paginated
    {
        $where = ['1 = 1'];
        $params = [];
        if ($filter->status !== null) {
            $where[] = 'b.status = :status';
            $params['status'] = $filter->status->value;
        }
        if ($filter->from !== null) {
            $where[] = 'b.event_date >= :from';
            $params['from'] = $filter->from->format('Y-m-d');
        }
        if ($filter->to !== null) {
            $where[] = 'b.event_date <= :to';
            $params['to'] = $filter->to->format('Y-m-d');
        }
        if ($filter->search !== '') {
            $where[] = '(b.code LIKE :q_code OR c.name LIKE :q_name OR c.phone LIKE :q_phone)';
            $like = '%' . addcslashes($filter->search, '%_\\') . '%';
            $params += ['q_code' => $like, 'q_name' => $like, 'q_phone' => $like];
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE $whereSql",
            $params
        );

        $sortColumn = match ($filter->sort) {
            'event_date' => 'b.event_date',
            'total' => 'b.total',
            'code' => 'b.code',
            'customer' => 'c.name',
            default => 'b.created_at',
        };
        $direction = $filter->direction === 'desc' ? 'DESC' : 'ASC';
        $pageCount = max(1, (int) ceil($total / BookingFilter::PER_PAGE));
        $page = min($filter->page, $pageCount);
        $offset = ($page - 1) * BookingFilter::PER_PAGE;

        $rows = $this->db->fetchAll(
            "SELECT b.id, b.code, b.status, b.event_date, b.location, b.guest_count, b.total, b.created_at,
                    b.deposit_percent, c.name AS customer_name, c.phone AS customer_phone,
                    (SELECT COALESCE(SUM(IF(p.kind = 'payment', p.amount, -CAST(p.amount AS SIGNED))), 0)
                       FROM payments p WHERE p.booking_id = b.id) AS paid_net
             FROM bookings b JOIN customers c ON c.id = b.customer_id
             WHERE $whereSql
             ORDER BY $sortColumn $direction, b.id $direction
             LIMIT " . BookingFilter::PER_PAGE . " OFFSET $offset",
            $params
        );

        return new Paginated($rows, $total, $page, BookingFilter::PER_PAGE);
    }

    /**
     * @return array<string, int> status => count
     */
    public function countsByStatus(): array
    {
        $counts = array_fill_keys(array_map(static fn (BookingStatus $s): string => $s->value, BookingStatus::cases()), 0);
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS n FROM bookings GROUP BY status') as $row) {
            $counts[Row::string($row, 'status')] = Row::int($row, 'n');
        }

        return $counts;
    }

    /**
     * Open bookings with an event in [from, to], ordered for the operations timeline.
     *
     * @return list<Booking>
     */
    public function upcoming(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_map(Booking::fromRow(...), $this->db->fetchAll(
            self::SELECT . " WHERE b.event_date BETWEEN :from AND :to
                AND b.status IN ('inquiry', 'quoted', 'confirmed')
             ORDER BY b.event_date, COALESCE(b.setup_start, b.event_date), b.code",
            ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')]
        ));
    }

    /**
     * Quoted and confirmed bookings, for the finance due list.
     *
     * @return list<array{booking: Booking, paid_net: int}>
     */
    public function receivables(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT b.*, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    (SELECT COALESCE(SUM(IF(p.kind = 'payment', p.amount, -CAST(p.amount AS SIGNED))), 0)
                       FROM payments p WHERE p.booking_id = b.id) AS paid_net
             FROM bookings b JOIN customers c ON c.id = b.customer_id
             WHERE b.status IN ('quoted', 'confirmed', 'completed') AND b.total > 0
             ORDER BY b.event_date"
        );

        return array_map(
            static fn (array $row): array => ['booking' => Booking::fromRow($row), 'paid_net' => Row::int($row, 'paid_net')],
            $rows
        );
    }
}
