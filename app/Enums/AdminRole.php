<?php

namespace App\Enums;

/** What an admin may do. Each admin has one role; only owners manage staff and settings. */
enum AdminRole: string
{
    case Owner = 'owner';
    case Support = 'support';
    case Finance = 'finance';
    case Catalogue = 'catalogue';
    case Marketing = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner (everything)',
            self::Support => 'Support',
            self::Finance => 'Finance',
            self::Catalogue => 'Catalogue',
            self::Marketing => 'Marketing',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Owner => 'Everything, including settings, the audit log and staff.',
            self::Support => 'Problems, chat reports, support chat, users, inspections; can view orders.',
            self::Finance => 'Payouts and refunds; can view orders.',
            self::Catalogue => 'Shops, products, categories, business checks and WiBuy Global.',
            self::Marketing => 'Rewards, lightning deals, price cuts, free trials and group buys.',
        };
    }
}
