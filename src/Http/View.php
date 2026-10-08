<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Policy;
use App\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final class View
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Policy $policy,
        private readonly Clock $clock,
        private readonly string $appName,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $data = [],
        int $status = 200,
    ): ResponseInterface {
        $response->getBody()->write($this->twig->render($template, $data + ['app' => $this->context($request)]));

        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public static function redirect(ResponseInterface $response, string $location, int $status = 303): ResponseInterface
    {
        return $response->withStatus($status)->withHeader('Location', $location);
    }

    /**
     * @param array<mixed> $data
     */
    public static function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }

    /**
     * @return array<string, mixed>
     */
    private function context(ServerRequestInterface $request): array
    {
        $session = RequestContext::session($request);
        $user = RequestContext::optionalUser($request);
        $form = $session->takeForm();

        return [
            'name' => $this->appName,
            'user' => $user,
            'can' => $user === null ? [] : $this->policy->abilityMap($user),
            'csrf' => $session->csrfToken(),
            'flash' => $session->takeFlash(),
            'old' => $form['input'],
            'errors' => $form['errors'],
            'path' => $request->getUri()->getPath(),
            'today' => $this->clock->now(),
        ];
    }
}
