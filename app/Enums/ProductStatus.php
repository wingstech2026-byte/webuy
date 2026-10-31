<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended'; // hidden by an admin

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Live',
            self::Suspended => 'Suspended',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'bg-brand-100 text-brand-800',
            self::Draft => 'bg-stone-200 text-stone-700',
            self::Suspended => 'bg-red-100 text-red-800',
        };
    }
}
