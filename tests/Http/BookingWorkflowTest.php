<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Auth\Role;
use App\Resource\ResourceType;
use Tests\Support\HttpTestCase;

final class BookingWorkflowTest extends HttpTestCase
{
    private int $package;
    private int $addon;
    private int $hall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->package = $this->makePackage('Resepsi Classic', 42_000_000);
        $this->addon = $this->makeAddon('Fresh flowers', 750_000);
        $this->hall = $this->makeResource('Pendopo Hall');
        $this->makeResource('Crew Melati', ResourceType::Crew);
        $this->makeUser(Role::Sales, 'sales@test.example.com');
        $this->makeUser(Role::Finance, 'finance@test.example.com');
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteForm(): array
    {
        return [
            'customer_name' => 'Dewi Anggraini',
            'customer_phone' => '0812 1111 2222',
            'event_date' => '2026-11-14',
            'location' => 'Pendopo Hall',
            'guest_count' => '280',
            'setup_start' => '2026-11-13T20:00',
            'teardown_end' => '2026-11-14T23:00',
            'package_id' => (string) $this->package,
            'addons' => [$this->addon => '4'],
            'resources' => [(string) $this->hall],
            'intent' => 'quote',
        ];
    }

    public function testSalesCreatesQuoteWithServerComputedTotal(): void
    {
        $this->loginAs('sales@test.example.com');

        // A tampered client-side total or price is ignored: the server recalculates.
        $response = $this->post('/bookings', $this->quoteForm() + ['total' => '1', 'subtotal' => '1']);

        self::assertSame('/bookings/1', $response->getHeaderLine('Location'));
        $html = $this->follow($response);
        self::assertStringContainsString('saved and marked as quoted', $html);
        self::assertStringContainsString('Rp 45.000.000', $html);
        self::assertSame('quoted', $this->bookingStatus(1));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM booking_resources WHERE released_at IS NULL'));
    }

    public function testSalesCannotSmugglePriceOverride(): void
    {
        $this->loginAs('sales@test.example.com');

        $response = $this->post('/bookings', $this->quoteForm() + ['package_price' => '1000']);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM bookings'));
    }

    public function testQuoteValidationErrorsAreFlashed(): void
    {
        $this->loginAs('sales@test.example.com');
        $form = ['setup_start' => '2026-11-15T20:00', 'teardown_end' => '2026-11-15T10:00', 'discount' => '500000'] + $this->quoteForm();

        $response = $this->request(
            'POST',
            '/bookings',
            ['_csrf' => $this->csrfToken()] + $form,
            ['Referer' => 'http://localhost/bookings/new']
        );

        $html = $this->follow($response);
        self::assertStringContainsString('Teardown must end after setup starts.', $html);
        self::assertStringContainsString('Give a reason for the discount.', $html);
        self::assertStringContainsString('value="Dewi Anggraini"', $html);
    }

    public function testDoubleBookingAttemptShowsConflictFlash(): void
    {
        $this->loginAs('sales@test.example.com');
        $this->post('/bookings', $this->quoteForm());

        $response = $this->request(
            'POST',
            '/bookings',
            ['_csrf' => $this->csrfToken(), 'customer_phone' => '0812 3333 4444'] + $this->quoteForm(),
            ['Referer' => 'http://localhost/bookings/new']
        );

        self::assertStringContainsString('Pendopo Hall is already booked in that window (BK-2026-0001)', $this->follow($response));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM bookings'));
    }

    public function testInvalidTransitionIsAFriendlyFlash(): void
    {
        $this->loginAs('sales@test.example.com');
        $this->post('/bookings', $this->quoteForm());

        $response = $this->request(
            'POST',
            '/bookings/1/confirm',
            ['_csrf' => $this->csrfToken()],
            ['Referer' => 'http://localhost/bookings/1']
        );

        self::assertSame('/bookings/1', $response->getHeaderLine('Location'));
        self::assertStringContainsString(
            'This booking cannot be confirmed yet: the deposit has not been paid in full.',
            $this->follow($response)
        );
    }

    public function testFullFlowFromQuoteToConfirmation(): void
    {
        $this->loginAs('sales@test.example.com');
        $this->post('/bookings', $this->quoteForm());

        $this->sessionCookie = null;
        $this->loginAs('finance@test.example.com');
        $response = $this->post('/bookings/1/payments', [
            'kind' => 'payment', 'amount' => '13.500.000', 'method' => 'bank_transfer',
            'reference' => 'TRF-1', 'paid_at' => '2026-10-09T09:30',
        ]);
        self::assertStringContainsString('Payment recorded.', $this->follow($response));

        $this->sessionCookie = null;
        $this->loginAs('sales@test.example.com');
        $this->post('/bookings/1/confirm');

        self::assertSame('confirmed', $this->bookingStatus(1));
        self::assertSame(
            ['created', 'resource_assigned', 'status_changed', 'payment_recorded', 'status_changed'],
            $this->activityActions(1)
        );
    }

    public function testRefundRequiresANote(): void
    {
        $this->loginAs('sales@test.example.com');
        $this->post('/bookings', $this->quoteForm());
        $this->sessionCookie = null;
        $this->loginAs('finance@test.example.com');

        $response = $this->request('POST', '/bookings/1/payments', [
            '_csrf' => $this->csrfToken(), 'kind' => 'refund', 'amount' => '100', 'method' => 'cash', 'paid_at' => '2026-10-09T09:30',
        ], ['Referer' => 'http://localhost/bookings/1']);

        self::assertStringContainsString('Give a reason for the refund.', $this->follow($response));
    }

    public function testAvailabilityEndpoint(): void
    {
        $this->loginAs('sales@test.example.com');
        $this->post('/bookings', $this->quoteForm());

        $response = $this->get('/api/availability', ['start' => '2026-11-14T08:00', 'end' => '2026-11-14T12:00']);
        $data = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($data);
        $byName = array_column($data['resources'], 'available', 'name');
        self::assertSame(['Pendopo Hall' => false, 'Crew Melati' => true], $byName);
        self::assertSame('BK-2026-0001', $data['resources'][0]['conflicts'][0]['code']);

        $excluded = json_decode((string) $this->get('/api/availability', [
            'start' => '2026-11-14T08:00', 'end' => '2026-11-14T12:00', 'exclude' => '1',
        ])->getBody(), true);
        self::assertIsArray($excluded);
        self::assertTrue($excluded['resources'][0]['available']);
    }

    public function testAvailabilityEndpointValidatesInput(): void
    {
        $this->loginAs('sales@test.example.com');

        $response = $this->get('/api/availability', ['start' => 'tomorrow']);

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('end', $data['fields']);
    }

    public function testBookingListFiltersAndSearch(): void
    {
        $owner = $this->makeUser(Role::Owner);
        $this->makeBooking($owner, phone: '+6281200000001');
        $this->makeBooking($owner, quoted: false, phone: '+6281200000002');
        $this->loginAs('sales@test.example.com');

        $quoted = (string) $this->get('/bookings', ['status' => 'quoted'])->getBody();
        self::assertStringContainsString('BK-2026-0001', $quoted);
        self::assertStringNotContainsString('BK-2026-0002', $quoted);

        $none = (string) $this->get('/bookings', ['q' => 'no-such-customer'])->getBody();
        self::assertStringContainsString('No bookings match', $none);

        self::assertSame(200, $this->get('/bookings', ['sort' => 'DROP TABLE', 'page' => '-4', 'from' => 'nope'])->getStatusCode());
    }

    public function testMonthlyCsvExport(): void
    {
        $this->loginAs('sales@test.example.com');
        $this->post('/bookings', $this->quoteForm());
        $this->sessionCookie = null;
        $this->loginAs('finance@test.example.com');
        $this->post('/bookings/1/payments', [
            'kind' => 'payment', 'amount' => '13500000', 'method' => 'qris', 'reference' => '=cmd()', 'paid_at' => '2026-10-09T09:30',
        ]);

        $response = $this->get('/payments/export', ['month' => '2026-10']);

        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('payments-2026-10.csv', $response->getHeaderLine('Content-Disposition'));
        $lines = explode("\n", trim((string) $response->getBody()));
        self::assertSame('paid_at,booking,customer,type,method,reference,amount_idr,recorded_by', $lines[0]);
        self::assertSame(
            '"2026-10-09 09:30",BK-2026-0001,"Dewi Anggraini",payment,QRIS,\'=cmd(),13500000,"Finance Tester"',
            $lines[1]
        );
    }

    public function testRunSheetAndCalendarRender(): void
    {
        $this->loginAs('sales@test.example.com');
        $tomorrow = ['event_date' => '2026-10-10', 'setup_start' => '2026-10-10T08:00', 'teardown_end' => '2026-10-10T22:00'];
        $this->post('/bookings', $tomorrow + $this->quoteForm());

        $today = (string) $this->get('/today')->getBody();
        self::assertStringContainsString('Dewi Anggraini', $today);
        self::assertStringContainsString('Deposit missing', $today);
        self::assertStringContainsString('No crew assigned', $today);

        $calendar = (string) $this->get('/calendar', ['month' => '2026-10'])->getBody();
        self::assertStringContainsString('BK-2026-0001', $calendar);
        self::assertSame(200, $this->get('/calendar', ['month' => 'garbage'])->getStatusCode());
    }
}
