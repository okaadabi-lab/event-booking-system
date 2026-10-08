<?php

declare(strict_types=1);

namespace App\Payment;

enum PaymentKind: string
{
    case Payment = 'payment';
    case Refund = 'refund';
}
