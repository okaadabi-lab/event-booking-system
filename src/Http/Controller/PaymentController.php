<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Auth\Ability;
use App\Auth\Policy;
use App\Http\RequestContext;
use App\Http\View;
use App\Operations\OperationsBoard;
use App\Payment\PaymentKind;
use App\Payment\PaymentMethod;
use App\Payment\PaymentRepository;
use App\Payment\PaymentService;
use App\Support\Clock;
use App\Support\Csv;
use App\Support\FormInput;
use App\Support\Row;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PaymentController
{
    public function __construct(
        private readonly View $view,
        private readonly PaymentService $payments,
        private readonly PaymentRepository $paymentRecords,
        private readonly OperationsBoard $board,
        private readonly Policy $policy,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array{id: string} $args
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = RequestContext::user($request);
        $input = new FormInput(RequestContext::body($request));
        $kind = $input->enum('kind', 'entry type', PaymentKind::class);
        $this->policy->authorize($user, $kind === PaymentKind::Refund ? Ability::RecordRefund : Ability::RecordPayment);

        $amount = $input->money('amount', 'Amount');
        $method = $input->enum('method', 'payment method', PaymentMethod::class);
        $reference = $input->string('reference', 'Reference', false, 100) ?? '';
        $paidAt = $input->dateTime('paid_at', 'Paid at');
        $note = $input->string('note', 'Note', false, 255) ?? '';
        if ($kind === PaymentKind::Refund && $note === '') {
            $input->addError('note', 'Give a reason for the refund.');
        }
        $input->throwIfInvalid();
        assert($kind instanceof PaymentKind && $method instanceof PaymentMethod && $amount !== null && $paidAt !== null);

        $this->payments->record((int) $args['id'], $kind, $amount, $method, $reference, $paidAt, $user, $note);
        RequestContext::session($request)->flash('success', $kind === PaymentKind::Payment ? 'Payment recorded.' : 'Refund recorded.');

        return View::redirect($response, "/bookings/{$args['id']}");
    }

    public function due(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->policy->authorize(RequestContext::user($request), Ability::ViewFinance);
        $today = $this->clock->now();
        $receivables = $this->board->receivables($today);

        $totals = ['overdue' => 0, 'due_30' => 0, 'later' => 0, 'deposits' => 0];
        $in30 = $today->setTime(0, 0)->modify('+30 days');
        foreach ($receivables as $entry) {
            $outstanding = $entry['schedule']->outstanding();
            if ($entry['overdue']) {
                $totals['overdue'] += $outstanding;
            } elseif ($entry['schedule']->balanceDueDate() <= $in30) {
                $totals['due_30'] += $outstanding;
            } else {
                $totals['later'] += $outstanding;
            }
            $totals['deposits'] += $entry['schedule']->depositRemaining();
        }

        $months = [];
        for ($i = 0; $i < 6; $i++) {
            $months[] = $today->modify('first day of this month')->modify("-$i months");
        }

        return $this->view->render($request, $response, 'payments/due.twig', [
            'receivables' => $receivables,
            'totals' => $totals,
            'months' => $months,
        ]);
    }

    public function export(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->policy->authorize(RequestContext::user($request), Ability::ViewFinance);
        $month = RequestContext::query($request)['month'] ?? '';
        $start = is_string($month) ? DateTimeImmutable::createFromFormat('!Y-m', $month, $this->clock->now()->getTimezone()) : false;
        if ($start === false || $start->format('Y-m') !== $month) {
            RequestContext::session($request)->flash('error', 'Choose a month to export.');

            return View::redirect($response, '/payments');
        }

        $rows = [];
        foreach ($this->paymentRecords->receivedInMonth($start) as $row) {
            $amount = Row::int($row, 'amount');
            $rows[] = [
                Row::date($row, 'paid_at')->format('Y-m-d H:i'),
                Row::string($row, 'booking_code'),
                Row::string($row, 'customer_name'),
                Row::string($row, 'kind'),
                PaymentMethod::from(Row::string($row, 'method'))->label(),
                Row::string($row, 'reference'),
                Row::string($row, 'kind') === 'refund' ? -$amount : $amount,
                Row::string($row, 'recorded_by_name'),
            ];
        }
        $csv = Csv::build(
            ['paid_at', 'booking', 'customer', 'type', 'method', 'reference', 'amount_idr', 'recorded_by'],
            $rows
        );
        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="payments-' . $start->format('Y-m') . '.csv"');
    }
}
