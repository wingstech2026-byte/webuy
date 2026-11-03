<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\PointTransaction;
use App\Models\User;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Support\Collection;

/**
 * "My rewards": WiBuy Points and coupons in one place, with the history and what expires soon. Never cash:
 * points become coupons, and coupons only lower the price of an order.
 */
class MyRewardsService
{
    public const SOON_DAYS = 7;

    /** @return array{points: int, points_per_coupon: int, coupon_from_points: string, can_exchange: bool, history: Collection, usable: Collection, expiring_soon: Collection, locked: Collection, used: Collection, expired: Collection, saved: string} */
    public function summary(User $user): array
    {
        $coupons = Coupon::where('user_id', $user->id)->with('product')->latest('id')->limit(200)->get();
        $usable = $coupons->filter(fn (Coupon $c) => $c->isUsable())->sortBy(fn (Coupon $c) => $c->expires_at?->timestamp ?? PHP_INT_MAX)->values();
        $cost = RewardSettings::int('points_per_coupon');

        return [
            'points' => (int) $user->points_balance,
            'points_per_coupon' => $cost,
            'coupon_from_points' => RewardSettings::money('points_coupon_amount'),
            'can_exchange' => $cost > 0 && $user->points_balance >= $cost,
            'history' => PointTransaction::where('user_id', $user->id)->latest('id')->limit(50)->get(['points', 'reason', 'created_at']),
            'usable' => $usable,
            'expiring_soon' => $usable->filter(fn (Coupon $c) => $c->expires_at !== null && $c->expires_at->lte(now()->addDays(self::SOON_DAYS)))->values(),
            'locked' => $coupons->where('status', Coupon::LOCKED)->values(),
            'used' => $coupons->where('status', Coupon::USED)->values(),
            'expired' => $coupons->filter(fn (Coupon $c) => $c->status === Coupon::EXPIRED || ($c->status === Coupon::AVAILABLE && $c->expires_at?->isPast()))->values(),
            'saved' => Money::sum($coupons->where('status', Coupon::USED)->pluck('discount_given')->filter()),
        ];
    }

    /** Daily: "Your Le 20 coupon ends in 3 days" (once, on the day it is 3 days away). */
    public function remindExpiring(): int
    {
        // A window of exactly one day (open at the start), so a daily run tells each coupon once.
        $coupons = Coupon::usable()->where('expires_at', '>', now()->addDays(2))->where('expires_at', '<=', now()->addDays(3))->with('user')->get()->groupBy('user_id');

        foreach ($coupons as $list) {
            $first = $list->first();
            app(NotificationService::class)->notify($first->user, '⏰ Coupon ends soon', ($list->count() > 1 ? "{$list->count()} coupons end" : "\"{$first->title}\" ({$first->description}) ends").' in 3 days. Use it before it is gone.', route('rewards.mine'));
        }

        return $coupons->count();
    }
}
