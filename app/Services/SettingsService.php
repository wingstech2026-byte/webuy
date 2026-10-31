<?php

namespace App\Services;

use App\Models\Setting;

/** Rules the admin can change without a deploy. Falls back to config/wibuy.php. */
class SettingsService
{
    /** Percent of an order's item subtotal that WiBuy keeps. */
    public static function commissionPercent(): string
    {
        return (string) Setting::get('commission_percent', config('wibuy.commission_percent'));
    }

    /** Smallest payout a seller can request. */
    public static function minPayout(): string
    {
        return (string) Setting::get('min_payout', config('wibuy.min_payout'));
    }
}
