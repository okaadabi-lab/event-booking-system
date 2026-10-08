<?php

declare(strict_types=1);

namespace App\Booking;

use App\Support\DomainRuleViolation;
use App\Support\Money;

/**
 * The only place a booking total is computed. public/assets/js/quote-builder.js
 * mirrors this for the live preview; the server result is what gets stored.
 */
final class QuoteCalculator
{
    /**
     * @param list<QuoteLine> $lines
     */
    public static function calculate(array $lines, int $discount): QuoteTotals
    {
        $subtotal = 0;
        foreach ($lines as $line) {
            $subtotal += $line->lineTotal();
        }
        if ($subtotal > Money::MAX_AMOUNT) {
            throw new DomainRuleViolation('The quote total is larger than the system allows.');
        }
        if ($discount < 0) {
            throw new DomainRuleViolation('The discount cannot be negative.');
        }
        if ($discount > $subtotal) {
            throw new DomainRuleViolation('The discount cannot be larger than the subtotal.');
        }

        return new QuoteTotals($subtotal, $discount, $subtotal - $discount);
    }
}
