<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Booking\BookingRepository;
use App\Http\RequestContext;
use App\Http\View;
use App\Operations\OperationsBoard;
use App\Support\Clock;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OperationsController
{
    private const RANGES = ['today' => 1, 'week' => 7, 'fortnight' => 14];

    public function __construct(
        private readonly View $view,
        private readonly OperationsBoard $board,
        private readonly BookingRepository $bookings,
        private readonly Clock $clock,
    ) {
    }

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::user($request);

        return View::redirect($response, $user->role->value === 'finance' ? '/payments' : '/today', 302);
    }

    public function today(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $range = RequestContext::query($request)['range'] ?? 'week';
        $range = is_string($range) && isset(self::RANGES[$range]) ? $range : 'week';
        $today = $this->clock->now();
        $timeline = $this->board->timeline($today, self::RANGES[$range]);

        $flagged = 0;
        $events = 0;
        foreach ($timeline as $day) {
            foreach ($day['events'] as $event) {
                $events++;
                $flagged += $event['flags'] === [] ? 0 : 1;
            }
        }

        return $this->view->render($request, $response, 'operations/today.twig', [
            'timeline' => $timeline,
            'range' => $range,
            'ranges' => self::RANGES,
            'event_count' => $events,
            'flagged_count' => $flagged,
            'counts' => $this->bookings->countsByStatus(),
        ]);
    }

    public function calendar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $now = $this->clock->now();
        $month = RequestContext::query($request)['month'] ?? '';
        $first = is_string($month) ? DateTimeImmutable::createFromFormat('!Y-m', $month, $now->getTimezone()) : false;
        if ($first === false || $first->format('Y-m') !== $month) {
            $first = $now->modify('first day of this month')->setTime(0, 0);
        }

        return $this->view->render($request, $response, 'operations/calendar.twig', [
            'month' => $first,
            'previous' => $first->modify('-1 month'),
            'next' => $first->modify('+1 month'),
            'grid' => $this->board->monthGrid($first),
        ]);
    }
}
