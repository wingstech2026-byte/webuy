<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Every reward and limit of the engagement features. The admin changes them in /admin/rewards;
 * until then the defaults below apply. All rewards are coupons or WiBuy Points, never cash.
 */
final class RewardSettings
{
    /**
     * key => [group, label, type (int | money | bool | text), default, help]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string|int|bool, 4?: string}>
     */
    public const FIELDS = [
        'welcome_coupon_count' => ['Welcome pack', 'Coupons unlocked at first login', 'int', 2],
        'welcome_coupon_amount' => ['Welcome pack', 'Value of each welcome coupon (Le)', 'money', '10.00'],
        'welcome_coupon_min_spend' => ['Welcome pack', 'Minimum order for welcome coupons (Le)', 'money', '50.00'],
        'welcome_bonus_amount' => ['Welcome pack', 'Bonus coupon value (Le), unlocked when a friend you invited signs up', 'money', '20.00'],
        'welcome_bonus_min_spend' => ['Welcome pack', 'Minimum order for the bonus coupon (Le)', 'money', '100.00'],
        'welcome_coupon_days' => ['Welcome pack', 'Days the welcome coupons last', 'int', 30],
        'referral_points' => ['Welcome pack', 'WiBuy Points for each friend who signs up from your link', 'int', 100],

        'app_first_order_amount' => ['App install', 'First app order coupon (Le) shown on the website banner', 'money', '15.00'],
        'app_first_order_min_spend' => ['App install', 'Minimum order for the app coupon (Le)', 'money', '50.00'],
        'app_store_url' => ['App install', 'Play Store link of the app', 'text', 'https://play.google.com/store/apps/details?id=sl.wibuy.wibuy_mobile'],

        'check_in_points' => ['Check-in and points', 'Points for a daily check-in', 'int', 10],
        'check_in_streak_bonus' => ['Check-in and points', 'Extra points per day of streak', 'int', 5],
        'check_in_max_streak' => ['Check-in and points', 'Streak counted up to (days)', 'int', 7],
        'points_per_coupon' => ['Check-in and points', 'Points needed to exchange for one coupon', 'int', 500],
        'points_coupon_amount' => ['Check-in and points', 'Value of that coupon (Le)', 'money', '5.00'],

        'spin_free_per_day' => ['Spin wheel', 'Free spins per day', 'int', 1],
        'spin_max_per_day' => ['Spin wheel', 'Most spins per day from free spins and shares', 'int', 3],
        'spin_pass_extra' => ['Spin wheel', 'Extra spins per day for WiBuy Pass members', 'int', 1],

        'price_cut_max_active' => ['Cut the price', 'Price cuts one person can run at the same time', 'int', 3],

        'lightning_slot_hours' => ['Lightning deals', 'Length of an automatic lightning deal (hours)', 'int', 2],
        'lightning_slots_per_day' => ['Lightning deals', 'Automatic lightning deals per day', 'int', 4],
        'lightning_discount_percent' => ['Lightning deals', 'Automatic lightning deal discount (%)', 'int', 20],

        'farm_target' => ['WiBuy Farm', 'Water needed to grow a plant fully', 'int', 300],

        'trial_review_days' => ['Free trials', 'Days to write the trial review after delivery', 'int', 7],
        'trial_block_overdue' => ['Free trials', 'Block people who never reviewed from new trials', 'bool', true],

        'pass_price' => ['WiBuy Pass', 'Price of one month (Le)', 'money', '25.00'],
        'pass_days' => ['WiBuy Pass', 'Days in one month of WiBuy Pass', 'int', 30],
        'pass_free_delivery_min' => ['WiBuy Pass', 'Free delivery on shop orders from (Le)', 'money', '50.00'],
        'pass_reminder_days' => ['WiBuy Pass', 'SMS reminder this many days before it ends', 'int', 3],

        'quote_valid_days' => ['Business buying', 'Days a seller quote stays valid (price locked)', 'int', 14],
        'milestone_min_amount' => ['Business buying', 'Orders from this amount can be paid in parts (Le)', 'money', '2000.00'],
        'milestone_deposit_percent' => ['Business buying', 'Deposit for part payments (%)', 'int', 30],
        'inspection_fee' => ['Business buying', 'Pre-delivery inspection fee (Le)', 'money', '50.00'],
        'reliability_ship_days' => ['Business buying', 'Shipped within this many days counts as on time (plus lead time)', 'int', 2],
        'risk_price_percent' => ['Business buying', 'Warn when a price is below this % of similar products', 'int', 50],
        'assistant_daily_limit' => ['Business buying', 'AI assistant requests per person per day', 'int', 20],
    ];

    public static function int(string $key): int
    {
        return (int) self::raw($key);
    }

    public static function money(string $key): string
    {
        return Money::from(self::raw($key));
    }

    public static function bool(string $key): bool
    {
        return filter_var(self::raw($key), FILTER_VALIDATE_BOOL);
    }

    public static function text(string $key): string
    {
        return (string) self::raw($key);
    }

    private static function raw(string $key): mixed
    {
        $default = self::FIELDS[$key][3] ?? null;

        return Setting::get("rewards.{$key}", $default);
    }

    public static function put(string $key, mixed $value): void
    {
        Setting::put("rewards.{$key}", is_bool($value) ? ($value ? '1' : '0') : $value);
    }
}
