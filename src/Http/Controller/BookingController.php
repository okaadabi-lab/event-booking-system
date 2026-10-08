<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Activity\ActivityLog;
use App\Auth\Ability;
use App\Auth\Policy;
use App\Booking\Booking;
use App\Booking\BookingFilter;
use App\Booking\BookingRepository;
use App\Booking\BookingService;
use App\Booking\BookingStatus;
use App\Booking\QuoteForm;
use App\Booking\TransitionGuards;
use App\Catalog\CatalogRepository;
use App\Customer\CustomerRepository;
use App\Http\RequestContext;
use App\Http\View;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRepository;
use App\Resource\ResourceRepository;
use App\Resource\ResourceScheduler;
use App\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

final class BookingController
{
    public function __construct(
        private readonly View $view,
        private readonly BookingRepository $bookings,
        private readonly BookingService $service,
        private readonly CatalogRepository $catalog,
        private readonly CustomerRepository $customers,
        private readonly ResourceRepository $resources,
        private readonly ResourceScheduler $scheduler,
        private readonly PaymentRepository $payments,
        private readonly ActivityLog $activity,
        private readonly Policy $policy,
        private readonly Clock $clock,
        private readonly int $balanceDueDays,
        private readonly int $depositPercent,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->policy->authorize(RequestContext::user($request), Ability::ViewBookings);
        $filter = BookingFilter::fromQuery(RequestContext::query($request));
        $results = $this->bookings->search($filter);

        $rows = [];
        foreach ($results->items as $row) {
            $total = (int) $row['total'];
            $paid = (int) $row['paid_net'];
            $rows[] = $row + [
                'progress' => $total > 0 ? (int) min(100, floor(max(0, $paid) * 100 / $total)) : 0,
            ];
        }

        return $this->view->render($request, $response, 'bookings/index.twig', [
            'filter' => $filter,
            'results' => $results,
            'rows' => $rows,
            'statuses' => BookingStatus::cases(),
            'counts' => $this->bookings->countsByStatus(),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->policy->authorize(RequestContext::user($request), Ability::CreateBooking);

        return $this->view->render($request, $response, 'bookings/builder.twig', $this->builderData(null));
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = RequestContext::user($request);
        $this->policy->authorize($user, Ability::CreateBooking);
        $form = QuoteForm::fromInput(RequestContext::body($request), true);
        assert($form->customer !== null);

        $customer = $this->customers->findOrCreate($form->customer['name'], $form->customer['phone'], $form->customer['email']);
        $id = $this->service->createQuote($customer, $form->request, $user);
        $booking = $this->find($id, $request);

        RequestContext::session($request)->flash(
            'success',
            $booking->status === BookingStatus::Quoted
                ? "Quote {$booking->code} saved and marked as quoted."
                : "Booking {$booking->code} saved as a draft inquiry."
        );

        return View::redirect($response, "/bookings/$id");
    }

    /**
     * @param array{id: string} $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::user($request);
        $this->policy->authorize($user, Ability::ViewBookings);
        $booking = $this->find((int) $args['id'], $request);

        $paidNet = $this->bookings->paidNet($booking->id);
        $schedule = $booking->paymentSchedule($paidNet, $this->balanceDueDays);
        $assignments = $this->scheduler->assignmentsFor($booking->id);
        $conflicts = $this->scheduler->conflictsForBooking($booking);
        $items = $this->bookings->items($booking->id);
        $hasPackage = array_filter($items, static fn ($i): bool => $i->itemType === 'package') !== [];
        $assigned = array_map(static fn ($a): int => $a->resource->id, $assignments);
        $today = $this->clock->now();

        return $this->view->render($request, $response, 'bookings/show.twig', [
            'booking' => $booking,
            'items' => $items,
            'schedule' => $schedule,
            'next_due' => $schedule->nextDue(),
            'overdue' => $schedule->isOverdue($today),
            'assignments' => $assignments,
            'conflicts' => $conflicts,
            'available_resources' => array_values(array_filter(
                $this->resources->all(true),
                static fn ($r): bool => !in_array($r->id, $assigned, true)
            )),
            'payments' => $this->payments->forBooking($booking->id),
            'activity' => $this->activity->forEntity('booking', $booking->id),
            'allowed' => $this->policy->bookingAbilityMap($user, $booking->status),
            'checklist' => [
                'quote' => TransitionGuards::forQuote($hasPackage, $booking->window !== null),
                'confirm' => TransitionGuards::forConfirmation($schedule, count($assignments), count($conflicts)),
                'complete' => TransitionGuards::forCompletion($booking->completableFrom(), $today),
            ],
            'methods' => PaymentMethod::cases(),
            'buffer_minutes' => $this->scheduler->bufferMinutes(),
        ]);
    }

    /**
     * @param array{id: string} $args
     */
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::user($request);
        $booking = $this->find((int) $args['id'], $request);
        if (!$this->policy->allowsOnBooking($user, Ability::EditQuote, $booking->status)) {
            $this->policy->authorize($user, Ability::EditQuote);
            RequestContext::session($request)->flash('error', "A {$booking->status->value} booking's quote can no longer be changed.");

            return View::redirect($response, "/bookings/{$booking->id}");
        }

        return $this->view->render($request, $response, 'bookings/builder.twig', $this->builderData($booking));
    }

    /**
     * @param array{id: string} $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::user($request);
        $this->policy->authorize($user, Ability::EditQuote);
        $booking = $this->find((int) $args['id'], $request);
        $form = QuoteForm::fromInput(RequestContext::body($request), false);
        $this->service->updateQuote($booking->id, $form->request, $user);

        $after = $this->find($booking->id, $request);
        RequestContext::session($request)->flash(
            'success',
            $after->status !== $booking->status ? "Quote saved and marked as {$after->status->value}." : 'Quote saved.'
        );

        return View::redirect($response, "/bookings/{$booking->id}");
    }

    /**
     * @param array{id: string} $args
     */
    public function markQuoted(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->service->markQuoted((int) $args['id'], RequestContext::user($request));

        return $this->afterTransition($request, $response, (int) $args['id'], 'Marked as quoted. The customer can now pay the deposit.');
    }

    /**
     * @param array{id: string} $args
     */
    public function confirm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->service->confirm((int) $args['id'], RequestContext::user($request));

        return $this->afterTransition($request, $response, (int) $args['id'], 'Booking confirmed. The venue and crew are locked in.');
    }

    /**
     * @param array{id: string} $args
     */
    public function complete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->service->complete((int) $args['id'], RequestContext::user($request));

        return $this->afterTransition($request, $response, (int) $args['id'], 'Booking marked as completed.');
    }

    /**
     * @param array{id: string} $args
     */
    public function cancel(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $reason = RequestContext::body($request)['cancel_reason'] ?? '';
        $this->service->cancel((int) $args['id'], is_string($reason) ? $reason : '', RequestContext::user($request));

        return $this->afterTransition($request, $response, (int) $args['id'], 'Booking cancelled and its resources released.');
    }

    private function afterTransition(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $id,
        string $message,
    ): ResponseInterface {
        RequestContext::session($request)->flash('success', $message);

        return View::redirect($response, "/bookings/$id");
    }

    /**
     * @return array<string, mixed>
     */
    private function builderData(?Booking $booking): array
    {
        $items = $booking === null ? [] : $this->bookings->items($booking->id);
        $selected = ['package' => null, 'addons' => [], 'prices' => []];
        foreach ($items as $item) {
            if ($item->itemType === 'package') {
                $selected['package'] = $item->catalogId;
            } else {
                $selected['addons'][$item->catalogId] = $item->quantity;
            }
            $selected['prices'][$item->key()] = $item->unitPrice;
        }

        return [
            'booking' => $booking,
            'items' => $items,
            'selected' => $selected,
            'packages' => $this->catalog->packages(true),
            'addons' => $this->catalog->addons(true),
            'resources' => $this->resources->all(true),
            'assigned' => $booking === null ? [] : array_map(
                static fn ($a): int => $a->resource->id,
                $this->scheduler->assignmentsFor($booking->id)
            ),
            'deposit_percent' => $booking !== null ? $booking->depositPercent : $this->depositPercent,
            'buffer_minutes' => $this->scheduler->bufferMinutes(),
        ];
    }

    private function find(int $id, ServerRequestInterface $request): Booking
    {
        return $this->bookings->find($id) ?? throw new HttpNotFoundException($request);
    }
}
