<?php

namespace App\Enums;

enum SellerStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Suspended => 'Suspended',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Approved => 'bg-brand-100 text-brand-800',
            self::Pending => 'bg-amber-100 text-amber-800',
            self::Rejected, self::Suspended => 'bg-red-100 text-red-800',
        };
    }
}
