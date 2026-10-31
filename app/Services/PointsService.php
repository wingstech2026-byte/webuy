<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Coupon;
use App\Models\PointTransaction;
use App\Models\User;
use App\Support\RewardSettings;
use Illuminate\Support\Facades\DB;

/** WiBuy Points: earned from check-ins, spins and referrals; exchanged for coupons. Never for cash. */
class PointsService
{
    public function __construct(private CouponService $coupons) {}

    public function award(User $user, int $points, string $reason): void
    {
        if ($points <= 0) {
            return;
        }

        DB::transaction(function () use ($user, $points, $reason) {
            User::whereKey($user->id)->increment('points_balance', $points);
            PointTransaction::create(['user_id' => $user->id, 'points' => $points, 'reason' => $reason]);
        });

        $user->refresh();
    }

    /** @throws MarketplaceException */
    public function spend(User $user, int $points, string $reason): void
    {
        DB::transaction(function () use ($user, $points, $reason) {
            $balance = (int) User::whereKey($user->id)->lockForUpdate()->value('points_balance');

            if ($balance < $points) {
                throw new MarketplaceException("You need {$points} points. You have {$balance}.");
            }

            User::whereKey($user->id)->decrement('points_balance', $points);
            PointTransaction::create(['user_id' => $user->id, 'points' => -$points, 'reason' => $reason]);
        });

        $user->refresh();
    }

    /** @throws MarketplaceException */
    public function exchangeForCoupon(User $user): Coupon
    {
        $cost = RewardSettings::int('points_per_coupon');
        $amount = RewardSettings::money('points_coupon_amount');

        return DB::transaction(function () use ($user, $cost, $amount) {
            $this->spend($user, $cost, 'Exchanged for a coupon');

            return $this->coupons->issue($user, [
                'title' => 'Points coupon',
                'source' => 'points',
                'amount' => $amount,
                'expires_at' => now()->addDays(30),
            ]);
        });
    }
}
