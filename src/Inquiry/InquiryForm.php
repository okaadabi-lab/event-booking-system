<?php

declare(strict_types=1);

namespace App\Inquiry;

use App\Support\FormInput;
use DateTimeImmutable;

final class InquiryForm
{
    public const EVENT_TYPES = [
        'wedding' => 'Wedding reception',
        'engagement' => 'Engagement (lamaran)',
        'birthday' => 'Birthday',
        'corporate' => 'Corporate event',
        'other' => 'Something else',
    ];

    private function __construct(
        public readonly string $name,
        public readonly string $phone,
        public readonly ?string $email,
        public readonly string $eventType,
        public readonly DateTimeImmutable $eventDate,
        public readonly string $location,
        public readonly int $guestCount,
        public readonly ?string $message,
    ) {
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws \App\Support\ValidationFailed
     */
    public static function fromInput(array $data): self
    {
        $input = new FormInput($data);
        $name = $input->string('name', 'Your name', true, 120, 2);
        $phone = $input->phone('phone', 'Phone / WhatsApp');
        $email = $input->email('email', 'Email', false);
        $eventType = $input->raw('event_type');
        if (!array_key_exists($eventType, self::EVENT_TYPES)) {
            $input->addError('event_type', 'Choose the kind of event.');
        }
        $eventDate = $input->date('event_date', 'Event date');
        $location = $input->string('location', 'Event location', true, 255, 3);
        $guests = $input->int('guest_count', 'Number of guests', 1, 5000);
        $message = $input->string('message', 'Message', false, 2000);
        $input->throwIfInvalid();

        // Non-null after throwIfInvalid(); the asserts document that for static analysis.
        assert($name !== null && $phone !== null && $eventDate !== null && $location !== null && $guests !== null);

        return new self($name, $phone, $email, $eventType, $eventDate, $location, $guests, $message);
    }
}
