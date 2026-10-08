<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\User;
use App\Http\Session\Session;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Typed access to the attributes the middleware stack puts on the request.
 */
final class RequestContext
{
    public static function session(ServerRequestInterface $request): Session
    {
        $session = $request->getAttribute(Session::class);
        if (!$session instanceof Session) {
            throw new RuntimeException('SessionMiddleware did not run for this route.');
        }

        return $session;
    }

    public static function user(ServerRequestInterface $request): User
    {
        $user = $request->getAttribute(User::class);
        if (!$user instanceof User) {
            throw new RuntimeException('AuthMiddleware did not run for this route.');
        }

        return $user;
    }

    public static function optionalUser(ServerRequestInterface $request): ?User
    {
        $user = $request->getAttribute(User::class);

        return $user instanceof User ? $user : null;
    }

    /**
     * The direct peer address. X-Forwarded-For is deliberately ignored: without a
     * trusted proxy list it is attacker-controlled and would defeat rate limits.
     */
    public static function ip(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function query(ServerRequestInterface $request): array
    {
        return $request->getQueryParams();
    }

    /**
     * A same-origin path to send the user back to, taken from Referer.
     */
    public static function backPath(ServerRequestInterface $request, string $fallback = '/'): string
    {
        $referer = $request->getHeaderLine('Referer');
        if ($referer === '') {
            return $fallback;
        }
        $parts = parse_url($referer);
        if ($parts === false || (isset($parts['host']) && $parts['host'] !== $request->getUri()->getHost())) {
            return $fallback;
        }
        $path = $parts['path'] ?? '/';

        return self::safePath($path . (isset($parts['query']) ? '?' . $parts['query'] : ''), $fallback);
    }

    /**
     * Accepts only local absolute paths, so redirects can never leave the site.
     */
    public static function safePath(string $path, string $fallback = '/'): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return $fallback;
        }

        return $path;
    }
}
