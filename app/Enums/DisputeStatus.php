<?php

namespace App\Enums;

enum DisputeStatus: string
{
    case Open = 'open';
    case ResolvedBuyer = 'resolved_buyer';   // buyer refunded
    case ResolvedSeller = 'resolved_seller'; // money released to the seller

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::ResolvedBuyer => 'Decided: buyer refunded',
            self::ResolvedSeller => 'Decided: seller paid',
        };
    }

    public function badge(): string
    {
        return $this === self::Open ? 'bg-red-100 text-red-800' : 'bg-stone-200 text-stone-700';
    }
}
