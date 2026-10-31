<?php

namespace App\Enums;

enum PayoutStatus: string
{
    case Requested = 'requested';   // waiting for an admin
    case Processing = 'processing'; // approved, sent to pawaPay
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Waiting for approval',
            self::Processing => 'Being sent',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Paid => 'bg-brand-100 text-brand-800',
            self::Requested, self::Processing => 'bg-amber-100 text-amber-800',
            self::Rejected, self::Failed => 'bg-red-100 text-red-800',
        };
    }
}
