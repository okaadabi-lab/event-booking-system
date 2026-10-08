<?php

declare(strict_types=1);

namespace App\Inquiry;

use App\Activity\ActivityLog;
use App\Booking\BookingRepository;
use App\Booking\BookingStatus;
use App\Customer\CustomerRepository;
use App\Support\Clock;
use App\Support\Database;
use App\Support\RateLimiter;
use App\Support\ValidationFailed;
use DateTimeImmutable;

/**
 * The public, unauthenticated way in. Creates a customer (or reuses one by phone)
 * and an inquiry booking with no prices; sales turns it into a quote.
 */
final class InquiryService
{
    private const BUCKET = 'inquiry';

    public function __construct(
        private readonly Database $db,
        private readonly CustomerRepository $customers,
        private readonly BookingRepository $bookings,
        private readonly ActivityLog $activity,
        private readonly RateLimiter $limiter,
        private readonly Clock $clock,
        private readonly int $maxPerHour,
        private readonly int $depositPercent,
    ) {
    }

    public function isRateLimited(string $ip): bool
    {
        return $this->limiter->tooManyAttempts(self::BUCKET, $ip, $this->maxPerHour, 3600);
    }

    /**
     * Counts a submission against the IP's hourly limit. Called for honeypot hits
     * too, so a bot that keeps retrying is also slowed down.
     */
    public function countAttempt(string $ip): void
    {
        $this->limiter->hit(self::BUCKET, $ip);
    }

    public function submit(InquiryForm $form): string
    {
        $today = $this->clock->now()->setTime(0, 0);
        if ($form->eventDate < $today->modify('+1 day')) {
            throw new ValidationFailed(['event_date' => 'Choose an event date from tomorrow onwards.']);
        }
        if ($form->eventDate > $today->modify('+2 years')) {
            throw new ValidationFailed(['event_date' => 'We take bookings up to two years ahead.']);
        }

        return $this->db->transactional(function () use ($form): string {
            $customer = $this->customers->findOrCreate($form->name, $form->phone, $form->email);
            $now = $this->clock->now();
            $code = $this->bookings->nextCode((int) $now->format('Y'));
            $id = $this->bookings->insert([
                'code' => $code,
                'customer_id' => $customer->id,
                'status' => BookingStatus::Inquiry->value,
                'source' => 'inquiry',
                'event_date' => $form->eventDate->format('Y-m-d'),
                'location' => $form->location,
                'guest_count' => $form->guestCount,
                'deposit_percent' => $this->depositPercent,
                'notes' => $form->message,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $this->activity->record('booking', $id, 'created', 'Inquiry received from the website', null, null, [
                'customer' => $customer->name,
                'event_type' => $form->eventType,
            ]);

            return $code;
        });
    }

    public static function minimumEventDate(DateTimeImmutable $today): DateTimeImmutable
    {
        return $today->setTime(0, 0)->modify('+1 day');
    }
}
