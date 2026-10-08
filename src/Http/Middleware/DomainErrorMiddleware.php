<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\RequestContext;
use App\Http\View;
use App\Support\DomainRuleViolation;
use App\Support\ValidationFailed;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Turns expected failures on form posts into the usual web pattern: redirect back
 * to the form with a flash message (and the submitted values, for validation).
 * Controllers stay free of try/catch boilerplate.
 */
final class DomainErrorMiddleware implements MiddlewareInterface
{
    private const NEVER_REFILL = ['_csrf', 'password', 'password_confirmation'];

    public function __construct(private readonly ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (ValidationFailed $e) {
            if (str_starts_with($request->getUri()->getPath(), '/api/')) {
                return View::json($this->responses->createResponse(), ['error' => $e->getMessage(), 'fields' => $e->errors], 422);
            }
            $session = RequestContext::session($request);
            $input = array_diff_key(RequestContext::body($request), array_flip(self::NEVER_REFILL));
            $session->flashForm($input, $e->errors);
            $session->flash('error', count($e->errors) === 1 ? 'Check the highlighted field.' : 'Check the highlighted fields.');

            return View::redirect($this->responses->createResponse(), RequestContext::backPath($request));
        } catch (DomainRuleViolation $e) {
            if (str_starts_with($request->getUri()->getPath(), '/api/')) {
                return View::json($this->responses->createResponse(), ['error' => $e->getMessage()], 422);
            }
            $session = RequestContext::session($request);
            $session->flashForm(array_diff_key(RequestContext::body($request), array_flip(self::NEVER_REFILL)), []);
            $session->flash('error', $e->getMessage());

            return View::redirect($this->responses->createResponse(), RequestContext::backPath($request));
        }
    }
}
