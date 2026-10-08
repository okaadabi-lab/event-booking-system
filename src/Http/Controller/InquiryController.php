<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Http\RequestContext;
use App\Http\View;
use App\Inquiry\InquiryForm;
use App\Inquiry\InquiryService;
use App\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class InquiryController
{
    /** Hidden from people with CSS; bots that fill every field reveal themselves. */
    public const HONEYPOT_FIELD = 'company_website';

    public function __construct(
        private readonly View $view,
        private readonly InquiryService $inquiries,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderForm($request, $response, ['limited' => false, 'submitted' => []]);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ip = RequestContext::ip($request);
        $body = RequestContext::body($request);
        $session = RequestContext::session($request);

        if ($this->inquiries->isRateLimited($ip)) {
            $this->logger->warning('Inquiry rate limit hit', ['ip' => $ip]);

            return $this->renderForm($request, $response, [
                'limited' => true,
                'submitted' => array_diff_key($body, ['_csrf' => true, self::HONEYPOT_FIELD => true]),
            ], 429)->withHeader('Retry-After', '3600');
        }
        $this->inquiries->countAttempt($ip);

        $honeypot = $body[self::HONEYPOT_FIELD] ?? '';
        if (is_string($honeypot) && $honeypot !== '') {
            // Look identical to success so the bot learns nothing, but store nothing.
            $this->logger->notice('Inquiry honeypot triggered', ['ip' => $ip]);
            $session->set('inquiry_code', null);

            return View::redirect($response, '/inquiry/thanks');
        }

        $code = $this->inquiries->submit(InquiryForm::fromInput($body));
        $session->set('inquiry_code', $code);

        return View::redirect($response, '/inquiry/thanks');
    }

    public function thanks(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = RequestContext::session($request);
        $code = $session->get('inquiry_code');
        $session->remove('inquiry_code');

        return $this->view->render($request, $response, 'inquiry/thanks.twig', [
            'code' => is_string($code) ? $code : null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderForm(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $data,
        int $status = 200,
    ): ResponseInterface {
        return $this->view->render($request, $response, 'inquiry/form.twig', $data + [
            'event_types' => InquiryForm::EVENT_TYPES,
            'min_date' => InquiryService::minimumEventDate($this->clock->now()),
            'honeypot' => self::HONEYPOT_FIELD,
        ], $status);
    }
}
