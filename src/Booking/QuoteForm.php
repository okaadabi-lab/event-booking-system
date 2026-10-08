<?php

declare(strict_types=1);

namespace App\Booking;

use App\Resource\TimeWindow;
use App\Support\DomainRuleViolation;
use App\Support\FormInput;
use App\Support\Money;

/**
 * Parses the quote builder form into a QuoteRequest (and customer fields when
 * creating). Field-level problems are collected and thrown as ValidationFailed.
 */
final class QuoteForm
{
    /**
     * @param array{name: string, phone: string, email: ?string}|null $customer
     */
    private function __construct(public readonly QuoteRequest $request, public readonly ?array $customer)
    {
    }

    /**
     * @param array<array-key, mixed> $data
     * @throws \App\Support\ValidationFailed
     */
    public static function fromInput(array $data, bool $withCustomer): self
    {
        $input = new FormInput($data);

        $customer = null;
        if ($withCustomer) {
            $name = $input->string('customer_name', 'Customer name', true, 120, 2);
            $phone = $input->phone('customer_phone', 'Customer phone');
            $email = $input->email('customer_email', 'Customer email', false);
            if ($name !== null && $phone !== null) {
                $customer = ['name' => $name, 'phone' => $phone, 'email' => $email];
            }
        }

        $eventDate = $input->date('event_date', 'Event date');
        $location = $input->string('location', 'Location', true, 255, 3);
        $guests = $input->int('guest_count', 'Guest count', 1, 5000);
        $setupStart = $input->dateTime('setup_start', 'Setup start', false);
        $teardownEnd = $input->dateTime('teardown_end', 'Teardown end', false);
        $packageId = $input->int('package_id', 'Package', 1, PHP_INT_MAX, false);
        $discount = $input->money('discount', 'Discount', false) ?? 0;
        $discountReason = $input->string('discount_reason', 'Discount reason', false, 255);
        $notes = $input->string('notes', 'Internal notes', false, 2000);

        $window = null;
        if (($setupStart === null) !== ($teardownEnd === null) && !$input->hasError('setup_start') && !$input->hasError('teardown_end')) {
            $input->addError($setupStart === null ? 'setup_start' : 'teardown_end', 'Set both the setup start and the teardown end, or neither.');
        } elseif ($setupStart !== null && $teardownEnd !== null) {
            try {
                $window = new TimeWindow($setupStart, $teardownEnd);
            } catch (DomainRuleViolation $e) {
                $input->addError('teardown_end', $e->getMessage());
            }
            if ($window !== null && $eventDate !== null && !$window->covers($eventDate)) {
                $input->addError('setup_start', 'The setup-to-teardown window must include the event date.');
            }
        }
        if ($discount > 0 && $discountReason === null) {
            $input->addError('discount_reason', 'Give a reason for the discount.');
        }

        $addons = [];
        foreach ($input->array('addons') as $addonId => $quantity) {
            $q = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => QuoteLine::MAX_QUANTITY]]);
            if (!is_int($addonId) || $q === false) {
                $input->addError('addons', 'Add-on quantities must be whole numbers from 0 to ' . QuoteLine::MAX_QUANTITY . '.');
                continue;
            }
            if ($q > 0) {
                $addons[$addonId] = $q;
            }
        }

        $overrides = [];
        foreach ($input->array('price') as $key => $value) {
            if (!is_string($key) || preg_match('/^(package|addon):\d+$/', $key) !== 1 || !is_string($value) || trim($value) === '') {
                continue;
            }
            $amount = Money::parse($value);
            if ($amount === null) {
                $input->addError('price', 'Unit prices must be whole rupiah amounts.');
                continue;
            }
            $overrides[$key] = $amount;
        }

        $resourceIds = [];
        foreach ($input->array('resources') as $resourceId) {
            $id = filter_var($resourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $resourceIds[] = $id;
            }
        }

        $input->throwIfInvalid();
        assert($eventDate !== null && $location !== null && $guests !== null);

        return new self(new QuoteRequest(
            $eventDate,
            $location,
            $guests,
            $window,
            $packageId,
            $addons,
            $overrides,
            $discount,
            $discountReason,
            $notes,
            $input->raw('intent') === 'quote',
            $resourceIds,
        ), $customer);
    }
}
