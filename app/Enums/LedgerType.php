<?php

namespace App\Enums;

enum LedgerType: string
{
    case Sale = 'sale';                       // + order completed: total minus commission
    case Payout = 'payout';                   // - seller asked to be paid out
    case PayoutReversal = 'payout_reversal';  // + payout was rejected or failed
    case Adjustment = 'adjustment';           // +/- admin correction
    case DeliveryFee = 'delivery_fee';        // + a rider's fee for a delivered order that completed
    case PriceProtection = 'price_protection'; // - the price dropped soon after a sale: the buyer got the difference
    case LateShipping = 'late_shipping';       // - shipped later than promised: the buyer got a coupon
    case GenuineGuarantee = 'genuine_guarantee'; // - goods promised genuine were fake: the buyer got compensation
    case StationFee = 'station_fee';           // + a partner station's share for a parcel it handled
    case Sponsored = 'sponsored';              // - the seller paid to sponsor a product

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::Payout => 'Payout',
            self::PayoutReversal => 'Payout returned',
            self::Adjustment => 'Adjustment',
            self::DeliveryFee => 'Delivery fee',
            self::PriceProtection => 'Price protection',
            self::LateShipping => 'Late shipping',
            self::GenuineGuarantee => 'Genuine guarantee',
            self::StationFee => 'Station fee',
            self::Sponsored => 'Sponsored product',
        };
    }
}
