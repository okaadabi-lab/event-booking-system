<?php

declare(strict_types=1);

namespace App\Payment;

use DateTimeImmutable;

/**
 * Derived payment state for one booking. Pure value object: no database access,
 * so every rule here is unit tested directly.
 */
final class PaymentSchedule
{
    public function __construct(
        public readonly int $total,
        public readonly int $depositPercent,
        public readonly DateTimeImmutable $eventDate,
        public readonly int $balanceDueDaysBeforeEvent,
        public readonly int $paidNet,
    ) {
    }

    /**
     * Rounded up to the next rupiah so the deposit is never short by a fraction.
     */
    public function depositAmount(): int
    {
        return intdiv($this->total * $this->depositPercent + 99, 100);
    }

    public function depositPaid(): bool
    {
        return $this->total > 0 && $this->paidNet >= $this->depositAmount();
    }

    public function depositRemaining(): int
    {
        return max(0, $this->depositAmount() - $this->paidNet);
    }

    public function balanceDueDate(): DateTimeImmutable
    {
        return $this->eventDate->setTime(0, 0)->modify("-{$this->balanceDueDaysBeforeEvent} days");
    }

    public function outstanding(): int
    {
        return max(0, $this->total - $this->paidNet);
    }

    public function isFullyPaid(): bool
    {
        return $this->total > 0 && $this->outstanding() === 0;
    }

    /**
     * Money received beyond the total, e.g. after a discount was added later.
     */
    public function overpaid(): int
    {
        return max(0, $this->paidNet - $this->total);
    }

    /**
     * Overdue from the day after the balance due date while anything is still owed.
     */
    public function isOverdue(DateTimeImmutable $today): bool
    {
        return $this->outstanding() > 0 && $today->setTime(0, 0) > $this->balanceDueDate();
    }

    public function daysOverdue(DateTimeImmutable $today): int
    {
        if (!$this->isOverdue($today)) {
            return 0;
        }

        return (int) $this->balanceDueDate()->diff($today->setTime(0, 0))->days;
    }

    /**
     * What should be collected next and by when. The deposit has no fixed date of its
     * own: it gates confirmation, but becomes due with the balance if still unpaid then.
     *
     * @return array{stage: 'deposit'|'balance'|'paid', amount: int, due: ?DateTimeImmutable}
     */
    public function nextDue(): array
    {
        if ($this->total === 0 || $this->outstanding() === 0) {
            return ['stage' => 'paid', 'amount' => 0, 'due' => null];
        }
        if (!$this->depositPaid()) {
            return ['stage' => 'deposit', 'amount' => $this->depositRemaining(), 'due' => null];
        }

        return ['stage' => 'balance', 'amount' => $this->outstanding(), 'due' => $this->balanceDueDate()];
    }

    public function progressPercent(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return (int) min(100, floor(max(0, $this->paidNet) * 100 / $this->total));
    }
}
