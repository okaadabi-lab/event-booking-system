<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\User;
use App\Auth\UserRepository;
use App\Http\Session\Session;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly UserRepository $users, private readonly ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(Session::class);
        $userId = $session instanceof Session ? $session->userId() : null;
        $user = $userId === null ? null : $this->users->find($userId);

        if ($user === null || !$user->isActive) {
            if (str_starts_with($request->getUri()->getPath(), '/api/')) {
                $response = $this->responses->createResponse(401)->withHeader('Content-Type', 'application/json');
                $response->getBody()->write('{"error":"Sign in required."}');

                return $response;
            }
            if ($session instanceof Session && $request->getMethod() === 'GET') {
                $uri = $request->getUri();
                $session->set('intended', $uri->getPath() . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : ''));
            }

            return $this->responses->createResponse(302)->withHeader('Location', '/login');
        }

        return $handler->handle($request->withAttribute(User::class, $user));
    }
}
