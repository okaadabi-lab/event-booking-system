<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Auth\Ability;
use App\Auth\Policy;
use App\Http\RequestContext;
use App\Http\View;
use App\Resource\Conflict;
use App\Resource\ResourceScheduler;
use App\Resource\TimeWindow;
use App\Support\DomainRuleViolation;
use App\Support\FormInput;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ResourceController
{
    public function __construct(private readonly ResourceScheduler $scheduler, private readonly Policy $policy)
    {
    }

    /**
     * @param array{id: string} $args
     */
    public function assign(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::user($request);
        $this->policy->authorize($user, Ability::AssignResources);
        $resourceId = (int) (RequestContext::body($request)['resource_id'] ?? 0);
        $this->scheduler->assign((int) $args['id'], $resourceId, $user->id);
        RequestContext::session($request)->flash('success', 'Resource assigned.');

        return View::redirect($response, "/bookings/{$args['id']}");
    }

    /**
     * @param array{id: string, resourceId: string} $args
     */
    public function release(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::user($request);
        $this->policy->authorize($user, Ability::AssignResources);
        $this->scheduler->release((int) $args['id'], (int) $args['resourceId'], $user->id);
        RequestContext::session($request)->flash('success', 'Resource released.');

        return View::redirect($response, "/bookings/{$args['id']}");
    }

    /**
     * GET /api/availability?start=...&end=...&exclude=ID
     * Read-only preview for the quote builder; assignment re-checks under lock.
     */
    public function availability(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->policy->authorize(RequestContext::user($request), Ability::ViewBookings);
        $input = new FormInput(RequestContext::query($request));
        $start = $input->dateTime('start', 'Setup start');
        $end = $input->dateTime('end', 'Teardown end');
        $exclude = $input->int('exclude', 'Booking', 1, PHP_INT_MAX, false);
        $input->throwIfInvalid();
        assert($start !== null && $end !== null);

        try {
            $window = new TimeWindow($start, $end);
        } catch (DomainRuleViolation $e) {
            return View::json($response, ['error' => $e->getMessage()], 422);
        }

        $resources = [];
        foreach ($this->scheduler->availability($window, $exclude) as $entry) {
            $resources[] = [
                'id' => $entry['resource']->id,
                'name' => $entry['resource']->name,
                'type' => $entry['resource']->type->value,
                'available' => $entry['conflicts'] === [],
                'conflicts' => array_map(static fn (Conflict $c): array => [
                    'booking_id' => $c->bookingId,
                    'code' => $c->bookingCode,
                    'customer' => $c->customerName,
                    'window' => $c->window->format(),
                ], $entry['conflicts']),
            ];
        }

        return View::json($response, [
            'window' => $window->format(),
            'buffer_minutes' => $this->scheduler->bufferMinutes(),
            'resources' => $resources,
        ]);
    }
}
