<?php

namespace App\Enums;

enum LedgerType: string
{
    case Sale = 'sale';                       // + order completed: total minus commission
    case Payout = 'payout';                   // - seller asked to be paid out
    case PayoutReversal = 'payout_reversal';  // + payout was rejected or failed
    case Adjustment = 'adjustment';           // +/- admin correction
    case DeliveryFee = 'delivery_fee';        // + a rider's fee for a delivered order that completed

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Payout => 'Payout',
            self::PayoutReversal => 'Payout returned',
            self::Adjustment => 'Adjustment',
            self::DeliveryFee => 'Delivery fee',
        };
    }
}
