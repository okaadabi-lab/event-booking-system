<?php

declare(strict_types=1);

namespace App\Payment;

use App\Support\Database;
use DateTimeImmutable;

final class PaymentRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forBooking(int $bookingId): array
    {
        return $this->db->fetchAll(
            'SELECT p.*, u.name AS recorded_by_name
             FROM payments p JOIN users u ON u.id = p.recorded_by
             WHERE p.booking_id = :id ORDER BY p.paid_at, p.id',
            ['id' => $bookingId]
        );
    }

    /**
     * Payments and refunds with paid_at inside the month, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function receivedInMonth(DateTimeImmutable $month): array
    {
        $start = $month->modify('first day of this month')->setTime(0, 0);

        return $this->db->fetchAll(
            'SELECT p.*, b.code AS booking_code, c.name AS customer_name, u.name AS recorded_by_name
             FROM payments p
             JOIN bookings b ON b.id = p.booking_id
             JOIN customers c ON c.id = b.customer_id
             JOIN users u ON u.id = p.recorded_by
             WHERE p.paid_at >= :start AND p.paid_at < :end
             ORDER BY p.paid_at, p.id',
            ['start' => $start->format('Y-m-d H:i:s'), 'end' => $start->modify('+1 month')->format('Y-m-d H:i:s')]
        );
    }
}
