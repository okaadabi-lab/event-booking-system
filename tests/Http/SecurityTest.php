<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Auth\Role;
use App\Support\Config;
use Tests\Support\HttpTestCase;
use Tests\Support\TestConfig;

final class SecurityTest extends HttpTestCase
{
    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->makeUser(Role::Sales, 'sales@test.example.com');
        $this->loginAs('sales@test.example.com');
        $booking = $this->makeBooking($this->makeUser(Role::Owner));

        $response = $this->post("/bookings/$booking/cancel", ['cancel_reason' => 'Forged request'], false);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('This form has expired', (string) $response->getBody());
        self::assertSame('quoted', $this->bookingStatus($booking));
    }

    public function testPostWithWrongCsrfTokenIsRejected(): void
    {
        $response = $this->post('/login', ['email' => 'x@example.com', 'password' => 'x', '_csrf' => str_repeat('a', 64)]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCsrfTokenFromAnotherSessionIsRejected(): void
    {
        $token = $this->csrfToken();
        $this->sessionCookie = null;

        self::assertSame(403, $this->post('/inquiry', ['_csrf' => $token])->getStatusCode());
    }

    public function testSecurityHeadersArePresent(): void
    {
        $response = $this->get('/login');

        $csp = $response->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
    }

    public function testTemplatesContainNoInlineScriptsOrStyles(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $html = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, $file->getFilename());
            self::assertDoesNotMatchRegularExpression('/\s(style|on[a-z]+)=/i', $html, $file->getFilename());
        }
    }

    public function testOutputIsEscaped(): void
    {
        $owner = $this->makeUser(Role::Owner, 'owner@test.example.com');
        $id = $this->makeBooking($owner);
        $this->db->run("UPDATE customers SET name = '<script>alert(1)</script>'");
        $this->loginAs('owner@test.example.com');

        $html = (string) $this->get("/bookings/$id")->getBody();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testProductionErrorsHideDetails(): void
    {
        $config = TestConfig::get()->with(['env' => 'production', 'debug' => false]);
        $this->app = (require dirname(__DIR__, 2) . '/config/app.php')($config, $this->buildContainer($config));
        $this->makeUser(Role::Owner, 'owner@test.example.com');
        $this->loginAs('owner@test.example.com');
        $this->db->pdo()->exec('RENAME TABLE payments TO payments_hidden');

        try {
            $response = $this->get('/payments');
        } finally {
            $this->db->pdo()->exec('RENAME TABLE payments_hidden TO payments');
        }

        $body = (string) $response->getBody();
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('Something went wrong', $body);
        self::assertStringNotContainsString('SQLSTATE', $body);
        self::assertStringNotContainsString('event_booking_test', $body);
        self::assertStringNotContainsString('#0', $body);
    }

    public function testDemoCredentialsAreHiddenInProduction(): void
    {
        self::assertStringContainsString('lentera-demo-2026', (string) $this->get('/login')->getBody());

        $config = TestConfig::get()->with(['env' => 'production', 'debug' => false]);
        $this->app = (require dirname(__DIR__, 2) . '/config/app.php')($config, $this->buildContainer($config));

        self::assertStringNotContainsString('lentera-demo-2026', (string) $this->get('/login')->getBody());
    }

    public function testConfigNeverEnablesDebugInProduction(): void
    {
        $config = Config::fromEnvironment(['APP_ENV' => 'production', 'APP_DEBUG' => 'true'], '/tmp');

        self::assertFalse($config->debug);
    }

    public function testHealthEndpointReportsDatabase(): void
    {
        $response = $this->get('/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('ok', $data['status']);
        self::assertSame('ok', $data['database']);
    }

    public function testUnknownRouteIs404(): void
    {
        self::assertSame(404, $this->get('/nope')->getStatusCode());
    }
}
