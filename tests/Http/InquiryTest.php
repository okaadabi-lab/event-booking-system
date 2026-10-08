<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Http\Controller\InquiryController;
use Tests\Support\HttpTestCase;

final class InquiryTest extends HttpTestCase
{
    /**
     * @return array<string, string>
     */
    private static function validForm(): array
    {
        return [
            'name' => 'Ayu Pratiwi',
            'phone' => '0812 3456 7890',
            'email' => 'ayu@example.com',
            'event_type' => 'wedding',
            'event_date' => '2026-12-12',
            'location' => 'Pendopo Hall',
            'guest_count' => '250',
            'message' => 'White and sage palette please.',
        ];
    }

    public function testFormIsPublic(): void
    {
        $response = $this->get('/inquiry');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="' . InquiryController::HONEYPOT_FIELD . '"', (string) $response->getBody());
    }

    public function testValidInquiryCreatesCustomerAndInquiryBooking(): void
    {
        $response = $this->post('/inquiry', self::validForm());

        self::assertSame('/inquiry/thanks', $response->getHeaderLine('Location'));
        self::assertStringContainsString('BK-2026-0001', $this->follow($response));
        $row = $this->db->fetchOne('SELECT b.status, b.source, b.total, c.phone FROM bookings b JOIN customers c ON c.id = b.customer_id');
        self::assertSame(['status' => 'inquiry', 'source' => 'inquiry', 'total' => 0, 'phone' => '+6281234567890'], $row);
        self::assertSame(['created'], $this->activityActions(1));
    }

    public function testSecondInquiryFromSamePhoneReusesCustomer(): void
    {
        $this->post('/inquiry', self::validForm());
        $this->post('/inquiry', ['phone' => '+62 812-3456-7890', 'name' => 'Someone Else'] + self::validForm());

        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM customers'));
        self::assertSame('Ayu Pratiwi', $this->db->fetchValue('SELECT name FROM customers'));
        self::assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM bookings'));
    }

    public function testValidationErrorsAreShownNextToFieldsAndInputIsKept(): void
    {
        $response = $this->request('POST', '/inquiry', [
            '_csrf' => $this->csrfToken(),
            'name' => 'Ayu Pratiwi',
            'phone' => '123',
            'event_type' => 'rave',
            'event_date' => '2026-10-01',
            'location' => '',
            'guest_count' => '0',
        ], ['Referer' => 'http://localhost/inquiry']);

        self::assertSame(303, $response->getStatusCode());
        $html = $this->follow($response);
        self::assertStringContainsString('Phone / WhatsApp must be a phone number', $html);
        self::assertStringContainsString('Choose the kind of event.', $html);
        self::assertStringContainsString('Event location is required.', $html);
        self::assertStringContainsString('Number of guests must be a whole number between 1 and 5000.', $html);
        self::assertStringContainsString('value="Ayu Pratiwi"', $html);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM bookings'));
    }

    public function testPastEventDateIsRejected(): void
    {
        $response = $this->request('POST', '/inquiry', ['_csrf' => $this->csrfToken(), 'event_date' => '2026-10-09'] + self::validForm(), [
            'Referer' => 'http://localhost/inquiry',
        ]);

        self::assertStringContainsString('Choose an event date from tomorrow onwards.', $this->follow($response));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM bookings'));
    }

    public function testHoneypotLooksLikeSuccessButStoresNothing(): void
    {
        $response = $this->post('/inquiry', [InquiryController::HONEYPOT_FIELD => 'https://spam.example'] + self::validForm());

        self::assertSame('/inquiry/thanks', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('BK-', $this->follow($response));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM bookings'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM customers'));
    }

    public function testRateLimitPerIpReturns429(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/inquiry', ['phone' => '0812345600' . $i . '0'] + self::validForm());
        }

        $response = $this->post('/inquiry', ['phone' => '081234569999'] + self::validForm());

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('3600', $response->getHeaderLine('Retry-After'));
        self::assertStringContainsString('this one was not sent', (string) $response->getBody());
        self::assertSame(5, (int) $this->db->fetchValue('SELECT COUNT(*) FROM bookings'));

        $this->ip = '198.51.100.20';
        self::assertSame(303, $this->post('/inquiry', ['phone' => '081234569999'] + self::validForm())->getStatusCode());
    }

    public function testRateLimitStoresHashedKeysOnly(): void
    {
        $this->post('/inquiry', self::validForm());

        $stored = (string) $this->db->fetchValue('SELECT key_hash FROM rate_limit_hits');
        self::assertSame(hash('sha256', $this->ip), $stored);
    }
}
