<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Session\Session;
use App\Http\Session\SessionStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    public const COOKIE = 'ebs_session';

    public function __construct(private readonly SessionStore $store, private readonly string $secureCookie)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookieId = $request->getCookieParams()[self::COOKIE] ?? null;
        $session = (is_string($cookieId) ? $this->store->load($cookieId) : null) ?? Session::start();

        $response = $handler->handle($request->withAttribute(Session::class, $session));

        if ($session->isDestroyed()) {
            $this->store->save($session);

            return $response->withAddedHeader('Set-Cookie', $this->cookie($request, '', -1));
        }
        if ($session->isDirty()) {
            $this->store->save($session);
            if ($session->isNew() || $session->wasRegenerated()) {
                $response = $response->withAddedHeader('Set-Cookie', $this->cookie($request, $session->id(), 0));
            }
        } elseif (!$session->isNew()) {
            $this->store->touch($session);
        }

        return $response;
    }

    private function cookie(ServerRequestInterface $request, string $value, int $maxAge): string
    {
        $secure = match ($this->secureCookie) {
            'true' => true,
            'false' => false,
            default => $request->getUri()->getScheme() === 'https',
        };
        $parts = [self::COOKIE . '=' . $value, 'Path=/', 'HttpOnly', 'SameSite=Lax'];
        if ($maxAge < 0) {
            $parts[] = 'Max-Age=0';
        }
        if ($secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
