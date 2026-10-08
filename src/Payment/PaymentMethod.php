<?php

declare(strict_types=1);

namespace App\Payment;

enum PaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Qris = 'qris';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::Cash => 'Cash',
            self::Qris => 'QRIS',
            self::Card => 'Card',
        };
    }
}
