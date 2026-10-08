<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Session\Session;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twig\Environment;

/**
 * Synchronizer-token CSRF protection for every state-changing request. The token
 * comes from the "_csrf" form field or the X-CSRF-Token header.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly ResponseFactoryInterface $responses, private readonly Environment $twig)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        $session = $request->getAttribute(Session::class);
        $expected = $session instanceof Session ? $session->get('csrf_token') : null;
        $body = $request->getParsedBody();
        $submitted = is_array($body) && is_string($body['_csrf'] ?? null)
            ? $body['_csrf']
            : $request->getHeaderLine('X-CSRF-Token');

        if (!is_string($expected) || $submitted === '' || !hash_equals($expected, $submitted)) {
            $response = $this->responses->createResponse(403);
            $response->getBody()->write($this->twig->render('errors/csrf.twig'));

            return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        return $handler->handle($request);
    }
}
