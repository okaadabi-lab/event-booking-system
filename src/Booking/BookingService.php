<?php

declare(strict_types=1);

namespace App\Booking;

use App\Activity\ActivityLog;
use App\Auth\AccessDenied;
use App\Auth\Ability;
use App\Auth\Policy;
use App\Auth\User;
use App\Catalog\CatalogRepository;
use App\Customer\CustomerRepository;
use App\Resource\ResourceConflict;
use App\Resource\ResourceScheduler;
use App\Support\Clock;
use App\Support\Database;
use App\Support\DomainRuleViolation;

/**
 * All booking writes go through here. Each public method is one transaction:
 * lock the booking row, check the rules against fresh data, write, log.
 */
final class BookingService
{
    public function __construct(
        private readonly Database $db,
        private readonly BookingRepository $bookings,
        private readonly CatalogRepository $catalog,
        private readonly CustomerRepository $customers,
        private readonly ResourceScheduler $scheduler,
        private readonly ActivityLog $activity,
        private readonly Policy $policy,
        private readonly Clock $clock,
        private readonly int $depositPercent,
        private readonly int $balanceDueDaysBeforeEvent,
    ) {
    }

    /**
     * The customer is matched or created inside the same transaction, so a quote
     * that fails (for example on a resource conflict) leaves no orphan customer.
     *
     * @param array{name: string, phone: string, email: ?string} $customerDetails
     */
    public function createQuote(array $customerDetails, QuoteRequest $request, User $actor): int
    {
        $this->policy->authorize($actor, Ability::CreateBooking);

        return $this->db->transactional(function () use ($customerDetails, $request, $actor): int {
            $customer = $this->customers->findOrCreate(
                $customerDetails['name'],
                $customerDetails['phone'],
                $customerDetails['email']
            );
            $now = $this->clock->now();
            $lines = $this->resolveLines($request, [], $actor);
            $totals = QuoteCalculator::calculate($lines, $request->discount);

            $id = $this->bookings->insert([
                'code' => $this->bookings->nextCode((int) $now->format('Y')),
                'customer_id' => $customer->id,
                'status' => BookingStatus::Inquiry->value,
                'source' => 'staff',
                'event_date' => $request->eventDate->format('Y-m-d'),
                'location' => $request->location,
                'guest_count' => $request->guestCount,
                'setup_start' => $request->window?->start->format('Y-m-d H:i:s'),
                'teardown_end' => $request->window?->end->format('Y-m-d H:i:s'),
                'subtotal' => $totals->subtotal,
                'discount_amount' => $totals->discount,
                'discount_reason' => $totals->discount > 0 ? $request->discountReason : null,
                'total' => $totals->total,
                'deposit_percent' => $this->depositPercent,
                'notes' => $request->notes,
                'created_by' => $actor->id,
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $this->bookings->replaceItems($id, $lines);
            $this->activity->record('booking', $id, 'created', 'Booking created by staff', $actor->id, null, [
                'customer' => $customer->name,
                'total' => $totals->total,
            ]);
            $this->logPriceOverrides($id, $lines, [], $actor);
            $this->reserveResources($id, $request, $actor);

            if ($request->markQuoted) {
                $this->transitionToQuoted($id, $actor);
            }

            return $id;
        });
    }

    public function updateQuote(int $bookingId, QuoteRequest $request, User $actor): void
    {
        $this->db->transactional(function () use ($bookingId, $request, $actor): void {
            $booking = $this->lock($bookingId);
            if (!$this->policy->allowsOnBooking($actor, Ability::EditQuote, $booking->status)) {
                if (!$this->policy->allows($actor, Ability::EditQuote)) {
                    throw new AccessDenied('Your role does not allow editing quotes.');
                }
                throw new DomainRuleViolation("A {$booking->status->value} booking's quote can no longer be changed.");
            }

            $existing = [];
            foreach ($this->bookings->items($bookingId) as $item) {
                $existing[$item->key()] = $item;
            }
            $lines = $this->resolveLines($request, $existing, $actor);
            $totals = QuoteCalculator::calculate($lines, $request->discount);

            if ($request->window !== null) {
                $this->assertWindowFreeForAssignedResources($booking, $request);
            } elseif ($this->scheduler->assignmentsFor($bookingId) !== [] || $request->resourceIds !== []) {
                throw new DomainRuleViolation('Release the assigned resources before clearing the setup and teardown times.');
            }

            $this->bookings->update($bookingId, [
                'event_date' => $request->eventDate->format('Y-m-d'),
                'location' => $request->location,
                'guest_count' => $request->guestCount,
                'setup_start' => $request->window?->start->format('Y-m-d H:i:s'),
                'teardown_end' => $request->window?->end->format('Y-m-d H:i:s'),
                'subtotal' => $totals->subtotal,
                'discount_amount' => $totals->discount,
                'discount_reason' => $totals->discount > 0 ? $request->discountReason : null,
                'total' => $totals->total,
                'notes' => $request->notes,
            ]);
            $this->bookings->replaceItems($bookingId, $lines);

            $after = $this->lock($bookingId);
            if ($booking->auditState() !== $after->auditState()) {
                $this->activity->record(
                    'booking',
                    $bookingId,
                    'quote_updated',
                    'Quote updated',
                    $actor->id,
                    $booking->auditState(),
                    $after->auditState()
                );
            }
            $this->logPriceOverrides($bookingId, $lines, $existing, $actor);
            $this->reserveResources($bookingId, $request, $actor);

            if ($request->markQuoted && $after->status === BookingStatus::Inquiry) {
                $this->transitionToQuoted($bookingId, $actor);
            }
        });
    }

    public function markQuoted(int $bookingId, User $actor): void
    {
        $this->policy->authorize($actor, Ability::EditQuote);
        $this->db->transactional(fn () => $this->transitionToQuoted($bookingId, $actor));
    }

    public function confirm(int $bookingId, User $actor): void
    {
        $this->policy->authorize($actor, Ability::ConfirmBooking);
        $this->db->transactional(function () use ($bookingId, $actor): void {
            $booking = $this->lock($bookingId);
            // Locking the held resources stops a concurrent assignment from creating
            // an overlap between the conflict check below and the commit.
            $held = $this->scheduler->lockAssignedResources($bookingId);
            $schedule = $booking->paymentSchedule($this->bookings->paidNet($bookingId), $this->balanceDueDaysBeforeEvent);
            $conflicts = $this->scheduler->conflictsForBooking($booking);

            BookingStateMachine::assertTransition(
                $booking->status,
                BookingStatus::Confirmed,
                TransitionGuards::forConfirmation($schedule, count($held), count($conflicts))
            );
            $this->changeStatus($booking, BookingStatus::Confirmed, $actor, ['confirmed_at' => $this->now()]);
        });
    }

    public function complete(int $bookingId, User $actor): void
    {
        $this->policy->authorize($actor, Ability::CompleteBooking);
        $this->db->transactional(function () use ($bookingId, $actor): void {
            $booking = $this->lock($bookingId);
            BookingStateMachine::assertTransition(
                $booking->status,
                BookingStatus::Completed,
                TransitionGuards::forCompletion($booking->completableFrom(), $this->clock->now())
            );
            $this->changeStatus($booking, BookingStatus::Completed, $actor, ['completed_at' => $this->now()]);
        });
    }

    public function cancel(int $bookingId, string $reason, User $actor): void
    {
        $this->policy->authorize($actor, Ability::CancelBooking);
        $this->db->transactional(function () use ($bookingId, $reason, $actor): void {
            $booking = $this->lock($bookingId);
            BookingStateMachine::assertTransition(
                $booking->status,
                BookingStatus::Cancelled,
                TransitionGuards::forCancellation($reason)
            );
            $released = $this->scheduler->releaseAll($bookingId);
            $this->changeStatus($booking, BookingStatus::Cancelled, $actor, [
                'cancelled_at' => $this->now(),
                'cancel_reason' => trim($reason),
            ], ['reason' => trim($reason), 'released' => implode(', ', $released)]);
        });
    }

    private function transitionToQuoted(int $bookingId, User $actor): void
    {
        $booking = $this->lock($bookingId);
        $hasPackage = false;
        foreach ($this->bookings->items($bookingId) as $item) {
            $hasPackage = $hasPackage || $item->itemType === 'package';
        }
        BookingStateMachine::assertTransition(
            $booking->status,
            BookingStatus::Quoted,
            TransitionGuards::forQuote($hasPackage, $booking->window !== null)
        );
        $this->changeStatus($booking, BookingStatus::Quoted, $actor, ['quoted_at' => $this->now()]);
    }

    /**
     * @param array<string, scalar|null> $extraColumns
     * @param array<string, scalar|null> $extraAudit
     */
    private function changeStatus(
        Booking $booking,
        BookingStatus $to,
        User $actor,
        array $extraColumns,
        array $extraAudit = [],
    ): void {
        $this->bookings->update($booking->id, ['status' => $to->value] + $extraColumns);
        $this->activity->record(
            'booking',
            $booking->id,
            'status_changed',
            "Status changed from {$booking->status->value} to {$to->value}",
            $actor->id,
            ['status' => $booking->status->value],
            ['status' => $to->value] + $extraAudit
        );
    }

    /**
     * Builds quote lines from the catalog. Lines that already exist on the booking
     * keep their snapshot name and price, so catalog price changes never reprice a
     * saved quote. Only users allowed to override prices may set a unit price that
     * differs from the snapshot.
     *
     * @param array<string, BookingItem> $existing keyed by "type:id"
     * @return list<QuoteLine>
     */
    private function resolveLines(QuoteRequest $request, array $existing, User $actor): array
    {
        $wanted = [];
        if ($request->packageId !== null) {
            $wanted['package:' . $request->packageId] = 1;
        }
        foreach ($request->addonQuantities as $addonId => $quantity) {
            if ($quantity > 0) {
                $wanted['addon:' . $addonId] = $quantity;
            }
        }

        $lines = [];
        foreach ($wanted as $key => $quantity) {
            [$type, $id] = explode(':', $key);
            $id = (int) $id;
            $snapshot = $existing[$key] ?? null;
            if ($snapshot !== null) {
                $name = $snapshot->name;
                $catalogPrice = $snapshot->catalogPrice;
                $currentPrice = $snapshot->unitPrice;
            } else {
                $item = $this->catalog->find($type, $id);
                if ($item === null || !$item->isActive) {
                    throw new DomainRuleViolation('One of the selected items is no longer available. Reload the quote builder.');
                }
                $name = $item->name;
                $catalogPrice = $item->price;
                $currentPrice = $item->price;
            }

            $unitPrice = $request->priceOverrides[$key] ?? $currentPrice;
            if ($unitPrice !== $currentPrice && !$this->policy->allows($actor, Ability::OverridePrice)) {
                throw new AccessDenied('Your role does not allow changing unit prices.');
            }
            $lines[] = new QuoteLine($type, $id, $name, $catalogPrice, $unitPrice, $quantity);
        }

        return $lines;
    }

    /**
     * @param list<QuoteLine> $lines
     * @param array<string, BookingItem> $existing
     */
    private function logPriceOverrides(int $bookingId, array $lines, array $existing, User $actor): void
    {
        foreach ($lines as $line) {
            $previousPrice = isset($existing[$line->key()]) ? $existing[$line->key()]->unitPrice : $line->catalogPrice;
            if ($line->unitPrice === $previousPrice) {
                continue;
            }
            $this->activity->record(
                'booking',
                $bookingId,
                'price_override',
                "Unit price for {$line->name} changed",
                $actor->id,
                ['item' => $line->name, 'unit_price' => $previousPrice],
                ['item' => $line->name, 'unit_price' => $line->unitPrice, 'catalog_price' => $line->catalogPrice]
            );
        }
    }

    /**
     * Assigns the resources ticked in the quote builder, in ascending id order.
     * Any conflict aborts the whole save, so a quote is never stored half-reserved.
     */
    private function reserveResources(int $bookingId, QuoteRequest $request, User $actor): void
    {
        if ($request->resourceIds === []) {
            return;
        }
        $this->policy->authorize($actor, Ability::AssignResources);
        $ids = array_values(array_unique($request->resourceIds));
        sort($ids);
        foreach ($ids as $resourceId) {
            if (!$this->scheduler->isAssigned($bookingId, $resourceId)) {
                $this->scheduler->assign($bookingId, $resourceId, $actor->id);
            }
        }
    }

    /**
     * Re-checks the resources the booking already holds against its new window.
     * Held and newly requested resources are locked together in ascending id order,
     * keeping the lock order consistent with every other writer.
     */
    private function assertWindowFreeForAssignedResources(Booking $booking, QuoteRequest $request): void
    {
        if ($request->window === null) {
            return;
        }
        foreach ($this->scheduler->lockAssignedResources($booking->id, $request->resourceIds) as $resource) {
            if (!$this->scheduler->isAssigned($booking->id, $resource->id)) {
                continue;
            }
            $conflicts = $this->scheduler->findConflicts($resource, $request->window, $booking->id);
            if ($conflicts !== []) {
                throw new ResourceConflict($resource->name, $conflicts);
            }
        }
    }

    private function lock(int $bookingId): Booking
    {
        return $this->bookings->lockForUpdate($bookingId) ?? throw new DomainRuleViolation('Booking not found.');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
