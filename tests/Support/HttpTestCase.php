<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Dispatches requests through the real Slim app (same middleware stack as
 * public/index.php) and carries the session cookie between requests like a browser.
 */
abstract class HttpTestCase extends DatabaseTestCase
{
    /** @var App<\Psr\Container\ContainerInterface|null> */
    protected App $app;
    protected ?string $sessionCookie = null;
    protected string $ip = '203.0.113.10';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = (require dirname(__DIR__, 2) . '/config/app.php')(TestConfig::get(), $this->container);
        $this->sessionCookie = null;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    protected function get(string $path, array $query = [], array $headers = []): ResponseInterface
    {
        return $this->request('GET', $path . ($query === [] ? '' : '?' . http_build_query($query)), null, $headers);
    }

    /**
     * Posts a form. The CSRF token is added automatically unless $withCsrf is false.
     *
     * @param array<string, mixed> $data
     */
    protected function post(string $path, array $data = [], bool $withCsrf = true): ResponseInterface
    {
        if ($withCsrf && !array_key_exists('_csrf', $data)) {
            $data['_csrf'] = $this->csrfToken();
        }

        return $this->request('POST', $path, $data);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $uri, ?array $body = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $uri, [
            'REMOTE_ADDR' => $this->ip,
        ]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($this->sessionCookie !== null) {
            $request = $request->withCookieParams([SessionMiddleware::COOKIE => $this->sessionCookie]);
        }
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withParsedBody($body);
        }

        $response = $this->app->handle($request);
        $this->rememberCookie($response);

        return $response;
    }

    protected function csrfToken(): string
    {
        $html = (string) $this->get('/inquiry')->getBody();
        if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $html, $m) !== 1) {
            self::fail('No CSRF token found on the page.');
        }

        return $m[1];
    }

    protected function loginAs(string $email, string $password = 'correct-horse-battery'): ResponseInterface
    {
        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    /**
     * Follows a redirect the way a browser would and returns the page body.
     */
    protected function follow(ResponseInterface $response): string
    {
        self::assertContains($response->getStatusCode(), [302, 303], 'Expected a redirect.');

        return (string) $this->get($response->getHeaderLine('Location'))->getBody();
    }

    private function rememberCookie(ResponseInterface $response): void
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/^' . SessionMiddleware::COOKIE . '=([^;]*)/', $header, $m) === 1) {
                $this->sessionCookie = $m[1] === '' ? null : $m[1];
            }
        }
    }
}
