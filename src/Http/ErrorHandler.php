<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\AccessDenied;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpMethodNotAllowedException;
use Throwable;
use Twig\Environment;

/**
 * Last line of defence. Unexpected errors are logged with full detail and the
 * client gets a generic page; details are shown only when debug is enabled,
 * which Config never allows in production.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Throwable $exception): ResponseInterface
    {
        [$status, $title, $message] = $this->describe($exception);

        if ($status >= 500) {
            $this->logger->error($exception->getMessage(), [
                'exception' => $exception,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
            ]);
        }

        $response = $this->responses->createResponse($status);
        if ($exception instanceof HttpMethodNotAllowedException) {
            $response = $response->withHeader('Allow', implode(', ', $exception->getAllowedMethods()));
        }
        $detail = $this->debug && $status >= 500
            ? get_class($exception) . ': ' . $exception->getMessage() . "\n\n" . $exception->getTraceAsString()
            : null;

        if ($this->wantsJson($request)) {
            return View::json($response, array_filter(['error' => $message, 'detail' => $detail]), $status);
        }

        $response->getBody()->write($this->twig->render('errors/error.twig', [
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'detail' => $detail,
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @return array{int, string, string}
     */
    private function describe(Throwable $exception): array
    {
        if ($exception instanceof AccessDenied) {
            return [403, 'Not allowed', $exception->getMessage() . ' Ask an owner if you need access.'];
        }
        if ($exception instanceof HttpException) {
            $status = $exception->getCode();

            return match ($status) {
                404 => [404, 'Page not found', 'Nothing lives at this address. Check the link or use the navigation.'],
                405 => [405, 'Method not allowed', 'This address does not accept that kind of request.'],
                403 => [403, 'Not allowed', 'Your role does not allow this action.'],
                default => [$status, $exception->getTitle(), $exception->getDescription()],
            };
        }

        return [
            500,
            'Something went wrong',
            'The error has been logged. Try again, and if it keeps happening tell the owner what you were doing.',
        ];
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        return str_starts_with($path, '/api/') || $path === '/health'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }
}
