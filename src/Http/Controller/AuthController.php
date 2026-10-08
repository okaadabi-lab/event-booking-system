<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Auth\Authenticator;
use App\Auth\LoginThrottled;
use App\Http\RequestContext;
use App\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly Authenticator $auth,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (RequestContext::session($request)->userId() !== null) {
            return View::redirect($response, '/');
        }

        return $this->view->render($request, $response, 'auth/login.twig');
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = RequestContext::session($request);
        $body = RequestContext::body($request);
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $ip = RequestContext::ip($request);

        try {
            $user = $email === '' || $password === '' ? null : $this->auth->attempt($email, $password, $ip);
        } catch (LoginThrottled $e) {
            $this->logger->warning('Login throttled', ['email' => $email, 'ip' => $ip]);
            $session->flashForm(['email' => $email], []);
            $session->flash('error', $e->getMessage());

            return View::redirect($response, '/login')->withHeader('Retry-After', (string) $e->retryAfterSeconds);
        }

        if ($user === null) {
            $session->flashForm(['email' => $email], []);
            $session->flash('error', 'That email and password combination is not valid.');

            return View::redirect($response, '/login');
        }

        $intended = $session->get('intended');
        $session->regenerate();
        $session->remove('intended');
        $session->remove('csrf_token');
        $session->set('user_id', $user->id);
        $this->logger->info('User signed in', ['user_id' => $user->id]);

        return View::redirect($response, RequestContext::safePath(is_string($intended) ? $intended : '/', '/'));
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        RequestContext::session($request)->destroy();

        return View::redirect($response, '/login');
    }
}
