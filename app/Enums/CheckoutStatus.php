<?php

namespace App\Enums;

enum CheckoutStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
