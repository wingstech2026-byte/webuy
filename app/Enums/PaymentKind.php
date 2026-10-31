<?php

namespace App\Enums;

enum PaymentKind: string
{
    case Deposit = 'deposit';   // buyer pays WiBuy
    case Refund = 'refund';     // WiBuy pays the buyer back
    case Payout = 'payout';     // WiBuy pays a seller
    case Membership = 'membership'; // buyer pays for a month of WiBuy Pass
    case Charge = 'charge';     // buyer pays something else: an order milestone, an inspection fee, an import deposit or balance
}
