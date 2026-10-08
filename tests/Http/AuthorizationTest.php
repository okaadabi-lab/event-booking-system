<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Auth\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HttpTestCase;

final class AuthorizationTest extends HttpTestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function forbidden(): iterable
    {
        yield 'finance cannot open the quote builder' => ['finance', 'GET', '/bookings/new'];
        yield 'finance cannot create bookings' => ['finance', 'POST', '/bookings'];
        yield 'finance cannot cancel' => ['finance', 'POST', '/bookings/{id}/cancel'];
        yield 'finance cannot assign resources' => ['finance', 'POST', '/bookings/{id}/resources'];
        yield 'sales cannot record payments' => ['sales', 'POST', '/bookings/{id}/payments'];
        yield 'sales cannot see finance' => ['sales', 'GET', '/payments'];
        yield 'sales cannot export payments' => ['sales', 'GET', '/payments/export?month=2026-10'];
        yield 'sales cannot open setup' => ['sales', 'GET', '/setup'];
        yield 'sales cannot change prices' => ['sales', 'POST', '/setup/packages'];
        yield 'finance cannot manage users' => ['finance', 'POST', '/setup/users'];
    }

    #[DataProvider('forbidden')]
    public function testRoleIsForbidden(string $role, string $method, string $path): void
    {
        $booking = $this->makeBooking($this->makeUser(Role::Owner));
        $this->makeUser(Role::from($role), "$role@test.example.com");
        $this->loginAs("$role@test.example.com");
        $path = str_replace('{id}', (string) $booking, $path);

        $response = $method === 'GET'
            ? $this->request('GET', $path)
            : $this->post($path, [
                'kind' => 'payment', 'amount' => '1000', 'method' => 'cash', 'paid_at' => '2026-10-09T09:00',
                'cancel_reason' => 'Trying anyway', 'resource_id' => '1', 'name' => 'X', 'price' => '1',
                'email' => 'x@example.com', 'role' => 'owner', 'password' => 'long-enough-password',
            ]);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Not allowed', (string) $response->getBody());
        self::assertSame('quoted', $this->bookingStatus($booking));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM payments'));
    }

    public function testFinanceSeesRecordPaymentButNotStatusActions(): void
    {
        $booking = $this->makeBooking($this->makeUser(Role::Owner));
        $this->makeUser(Role::Finance, 'finance@test.example.com');
        $this->loginAs('finance@test.example.com');

        $html = (string) $this->get("/bookings/$booking")->getBody();

        self::assertStringContainsString('Record a payment or refund', $html);
        self::assertStringNotContainsString('Confirm booking', $html);
        self::assertStringNotContainsString('Cancel this booking', $html);
        self::assertStringNotContainsString('Edit quote', $html);
    }

    public function testSalesSeesStatusActionsButNotPayments(): void
    {
        $booking = $this->makeBooking($this->makeUser(Role::Owner));
        $this->makeUser(Role::Sales, 'sales@test.example.com');
        $this->loginAs('sales@test.example.com');

        $html = (string) $this->get("/bookings/$booking")->getBody();

        self::assertStringContainsString('Confirm booking', $html);
        self::assertStringNotContainsString('Record a payment or refund', $html);
        self::assertStringNotContainsString('href="/payments"', $html);
    }

    public function testFinanceLandsOnPaymentsAndSalesOnRunSheet(): void
    {
        $this->makeUser(Role::Finance, 'finance@test.example.com');
        $this->makeUser(Role::Sales, 'sales@test.example.com');

        $this->loginAs('finance@test.example.com');
        self::assertSame('/payments', $this->get('/')->getHeaderLine('Location'));

        $this->sessionCookie = null;
        $this->loginAs('sales@test.example.com');
        self::assertSame('/today', $this->get('/')->getHeaderLine('Location'));
    }

    public function testLastActiveOwnerCannotBeDemoted(): void
    {
        $owner = $this->makeUser(Role::Owner, 'owner@test.example.com');
        $this->loginAs('owner@test.example.com');

        $html = $this->follow($this->request('POST', '/setup/users', [
            '_csrf' => $this->csrfToken(), 'id' => (string) $owner->id, 'name' => $owner->name,
            'email' => $owner->email, 'role' => 'sales', 'is_active' => '1',
        ], ['Referer' => 'http://localhost/setup?tab=users']));

        self::assertStringContainsString('Keep at least one active owner account.', $html);
    }
}
