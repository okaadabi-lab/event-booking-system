<?php

declare(strict_types=1);

namespace App\Auth;

use App\Booking\BookingStatus;

/**
 * Single place that answers "may this user do this?". Role checks and
 * booking-state checks live together so the UI and the controllers agree.
 */
final class Policy
{
    private const ROLE_ABILITIES = [
        'sales' => [
            Ability::ViewBookings,
            Ability::CreateBooking,
            Ability::EditQuote,
            Ability::AssignResources,
            Ability::ConfirmBooking,
            Ability::CompleteBooking,
            Ability::CancelBooking,
        ],
        'finance' => [
            Ability::ViewBookings,
            Ability::RecordPayment,
            Ability::RecordRefund,
            Ability::ViewFinance,
        ],
    ];

    /**
     * Booking states in which each booking-level action makes sense.
     */
    private const ALLOWED_STATES = [
        'edit-quote' => [BookingStatus::Inquiry, BookingStatus::Quoted],
        'override-price' => [BookingStatus::Inquiry, BookingStatus::Quoted],
        'assign-resources' => [BookingStatus::Inquiry, BookingStatus::Quoted, BookingStatus::Confirmed],
        'confirm-booking' => [BookingStatus::Quoted],
        'complete-booking' => [BookingStatus::Confirmed],
        'cancel-booking' => [BookingStatus::Inquiry, BookingStatus::Quoted, BookingStatus::Confirmed],
        'record-payment' => [BookingStatus::Quoted, BookingStatus::Confirmed, BookingStatus::Completed],
        'record-refund' => [
            BookingStatus::Quoted,
            BookingStatus::Confirmed,
            BookingStatus::Completed,
            BookingStatus::Cancelled,
        ],
    ];

    public function allows(User $user, Ability $ability): bool
    {
        if (!$user->isActive) {
            return false;
        }
        if ($user->role === Role::Owner) {
            return true;
        }

        return in_array($ability, self::ROLE_ABILITIES[$user->role->value], true);
    }

    public function allowsOnBooking(User $user, Ability $ability, BookingStatus $status): bool
    {
        if (!$this->allows($user, $ability)) {
            return false;
        }
        $states = self::ALLOWED_STATES[$ability->value] ?? null;

        return $states === null || in_array($status, $states, true);
    }

    public function authorize(User $user, Ability $ability): void
    {
        if (!$this->allows($user, $ability)) {
            throw new AccessDenied('Your role does not allow this action.');
        }
    }

    /**
     * @return array<string, bool> ability value => allowed, for templates
     */
    public function abilityMap(User $user): array
    {
        $map = [];
        foreach (Ability::cases() as $ability) {
            $map[$ability->value] = $this->allows($user, $ability);
        }

        return $map;
    }

    /**
     * @return array<string, bool> ability value => allowed for this booking's current state
     */
    public function bookingAbilityMap(User $user, BookingStatus $status): array
    {
        $map = [];
        foreach (Ability::cases() as $ability) {
            $map[$ability->value] = $this->allowsOnBooking($user, $ability, $status);
        }

        return $map;
    }
}
