<?php

declare(strict_types=1);

namespace Tests\Http;

use App\Auth\Role;
use App\Auth\UserRepository;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Support\HttpTestCase;

final class AuthenticationTest extends HttpTestCase
{
    public function testProtectedPagesRedirectToLogin(): void
    {
        $response = $this->get('/bookings');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testApiReturns401InsteadOfRedirect(): void
    {
        $response = $this->get('/api/availability', ['start' => '2026-10-20T08:00', 'end' => '2026-10-20T20:00']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testLoginRegeneratesSessionAndSetsSafeCookieFlags(): void
    {
        $this->makeUser(Role::Sales, 'sales@test.example.com');
        $this->get('/login');
        $before = $this->sessionCookie;

        $response = $this->loginAs('sales@test.example.com');

        self::assertSame(303, $response->getStatusCode());
        self::assertNotNull($before);
        self::assertNotSame($before, $this->sessionCookie);
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertSame(200, $this->get('/bookings')->getStatusCode());

        // The pre-login session id must be dead.
        $this->sessionCookie = $before;
        self::assertSame(302, $this->get('/bookings')->getStatusCode());
    }

    public function testSecureCookieFlagOverHttps(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://localhost/login', ['REMOTE_ADDR' => $this->ip]);

        $cookie = $this->app->handle($request)->getHeaderLine('Set-Cookie');

        self::assertStringContainsString('Secure', $cookie);
    }

    public function testWrongPasswordShowsGenericError(): void
    {
        $this->makeUser(Role::Sales, 'sales@test.example.com');

        $html = $this->follow($this->loginAs('sales@test.example.com', 'wrong-password'));

        self::assertStringContainsString('That email and password combination is not valid.', $html);
    }

    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        $this->makeUser(Role::Sales, 'sales@test.example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->loginAs('sales@test.example.com', 'wrong-password');
        }

        $response = $this->loginAs('sales@test.example.com');

        self::assertNotEmpty($response->getHeaderLine('Retry-After'));
        self::assertStringContainsString('Too many failed sign-in attempts', $this->follow($response));
        self::assertSame(302, $this->get('/bookings')->getStatusCode(), 'Correct password must not get through while locked.');
    }

    public function testThrottleIsPerEmailAndIp(): void
    {
        $this->makeUser(Role::Sales, 'sales@test.example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->loginAs('sales@test.example.com', 'wrong-password');
        }

        $this->ip = '198.51.100.7';
        $this->sessionCookie = null;
        $this->loginAs('sales@test.example.com');

        self::assertSame(200, $this->get('/bookings')->getStatusCode());
    }

    public function testThrottleExpiresAfterTheWindow(): void
    {
        $this->makeUser(Role::Sales, 'sales@test.example.com');
        for ($i = 0; $i < 5; $i++) {
            $this->loginAs('sales@test.example.com', 'wrong-password');
        }

        $this->clock->set($this->clock->now()->modify('+16 minutes'));
        $this->loginAs('sales@test.example.com');

        self::assertSame(200, $this->get('/bookings')->getStatusCode());
    }

    public function testDeactivatedUserCannotSignIn(): void
    {
        $user = $this->makeUser(Role::Sales, 'sales@test.example.com');
        $this->container->make(UserRepository::class)->update($user->id, $user->name, $user->email, Role::Sales, false);

        $this->loginAs('sales@test.example.com');

        self::assertSame(302, $this->get('/bookings')->getStatusCode());
    }

    public function testLogoutRequiresPostAndEndsTheSession(): void
    {
        $this->makeUser(Role::Sales, 'sales@test.example.com');
        $this->loginAs('sales@test.example.com');

        self::assertSame(405, $this->get('/logout')->getStatusCode());

        $token = $this->csrfTokenFrom('/bookings');
        $cookie = $this->sessionCookie;
        $response = $this->post('/logout', ['_csrf' => $token]);

        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        $this->sessionCookie = $cookie;
        self::assertSame(302, $this->get('/bookings')->getStatusCode());
    }

    public function testOpenRedirectIsNotPossibleAfterLogin(): void
    {
        $this->makeUser(Role::Sales, 'sales@test.example.com');
        $this->get('//evil.example.com/path');

        $response = $this->loginAs('sales@test.example.com');

        self::assertStringStartsWith('/', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('evil', $response->getHeaderLine('Location'));
    }

    private function csrfTokenFrom(string $path): string
    {
        preg_match('/name="_csrf" value="([a-f0-9]{64})"/', (string) $this->get($path)->getBody(), $m);

        return $m[1] ?? '';
    }
}
