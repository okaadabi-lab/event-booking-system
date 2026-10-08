<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    // Scripts, styles and fonts are all served from this origin. No 'unsafe-inline':
    // templates contain no inline scripts, event handlers or style attributes.
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; font-src 'self'; "
        . "img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; "
        . "base-uri 'none'; object-src 'none'";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->getUri()->getScheme() === 'https') {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        if (!$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }
}
