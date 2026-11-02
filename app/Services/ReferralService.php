<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use App\Support\RewardSettings;

/**
 * Invite friends: when a friend who joined from your link gets their first order (it completes), you both get a
 * coupon and you get WiBuy Points. Once per friend; never cash. Joining already gives points (RewardService).
 */
class ReferralService
{
    public function __construct(private CouponService $coupons, private PointsService $points, private NotificationService $notifications) {}

    public function onOrderCompleted(Order $order): void
    {
        $friend = $order->buyer;

        if (! $friend?->referred_by_id || $friend->referral_rewarded_at !== null) {
            return;
        }

        // Only the first order that arrives counts (one reward per friend).
        $friend->forceFill(['referral_rewarded_at' => now()])->save();
        $inviter = $friend->referredBy;
        $amount = RewardSettings::money('referral_order_coupon');

        if ($inviter && Money::gt($amount, 0)) {
            foreach ([$inviter, $friend] as $user) {
                $this->coupons->issue($user, [
                    'title' => 'Thank you for inviting',
                    'source' => 'referral',
                    'amount' => $amount,
                    'min_spend' => RewardSettings::money('referral_order_min_spend'),
                    'expires_at' => now()->addDays(30),
                ]);
            }

            $this->points->award($inviter, RewardSettings::int('referral_order_points'), "{$friend->name} got their first WiBuy order");
            $this->notifications->notify($inviter, '🎁 Your friend ordered!', "{$friend->name} got their first order. You both have a ".Money::format($amount).' coupon.', route('invite.show'));
            $this->notifications->notify($friend, '🎁 A coupon for you', 'Thanks for joining WiBuy with a friend\'s link: here is a '.Money::format($amount).' coupon for your next order.', route('invite.show'));
        }
    }

    /** @return array{link: string, code: string, joined: int, ordered: int, coupon: string, points_join: int, points_order: int} */
    public function summary(User $user): array
    {
        $user = app(RewardService::class)->ensureReferralCode($user);
        $friends = User::where('referred_by_id', $user->id);

        return [
            'link' => route('home', ['ref' => $user->referral_code]),
            'code' => $user->referral_code,
            'joined' => (clone $friends)->count(),
            'ordered' => (clone $friends)->whereNotNull('referral_rewarded_at')->count(),
            'coupon' => RewardSettings::money('referral_order_coupon'),
            'points_join' => RewardSettings::int('referral_points'),
            'points_order' => RewardSettings::int('referral_order_points'),
        ];
    }
}
