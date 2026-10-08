<?php

declare(strict_types=1);

namespace App\Auth;

enum Ability: string
{
    case ViewBookings = 'view-bookings';
    case CreateBooking = 'create-booking';
    case EditQuote = 'edit-quote';
    case OverridePrice = 'override-price';
    case AssignResources = 'assign-resources';
    case ConfirmBooking = 'confirm-booking';
    case CompleteBooking = 'complete-booking';
    case CancelBooking = 'cancel-booking';
    case RecordPayment = 'record-payment';
    case RecordRefund = 'record-refund';
    case ViewFinance = 'view-finance';
    case ManageCatalog = 'manage-catalog';
    case ManageUsers = 'manage-users';
}
