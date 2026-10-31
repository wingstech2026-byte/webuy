<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\User;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Support\Str;

/**
 * What happens at login: devices, referral codes, the new-user welcome pack, the referral bonus
 * and the first-app-order coupon.
 */
class RewardService
{
    public function __construct(
        private CouponService $coupons,
        private PointsService $points,
        private DeviceService $devices,
    ) {}

    /** Call after every successful login (website and app). */
    public function afterLogin(User $user, bool $isNew, string $channel, ?string $referralCode, ?string $deviceHash): void
    {
        $this->devices->record($user, $deviceHash);
        $this->ensureReferralCode($user);

        if ($isNew) {
            $user->forceFill(['signup_channel' => $channel])->save();
            $this->grantWelcomePack($user);
            $this->creditReferrer($user, $referralCode, $deviceHash);
        }

        if ($channel === 'app') {
            $this->grantAppCoupon($user);
        }
    }

    public function ensureReferralCode(User $user): User
    {
        if (! $user->referral_code) {
            do {
                $code = Str::upper(Str::random(7));
            } while (User::where('referral_code', $code)->exists());

            $user->forceFill(['referral_code' => $code])->save();
        }

        return $user;
    }

    /** Some coupons now; the bonus coupon unlocks when a friend signs up from this person's link. */
    public function grantWelcomePack(User $user): void
    {
        if (Coupon::where('user_id', $user->id)->whereIn('source', ['welcome', 'welcome_bonus'])->exists()) {
            return;
        }

        $expires = now()->addDays(RewardSettings::int('welcome_coupon_days'));

        for ($i = 0; $i < RewardSettings::int('welcome_coupon_count'); $i++) {
            $this->coupons->issue($user, [
                'title' => 'Welcome to WiBuy',
                'source' => 'welcome',
                'amount' => RewardSettings::money('welcome_coupon_amount'),
                'min_spend' => RewardSettings::money('welcome_coupon_min_spend'),
                'expires_at' => $expires,
            ]);
        }

        $this->coupons->issue($user, [
            'title' => 'Welcome bonus',
            'source' => 'welcome_bonus',
            'amount' => RewardSettings::money('welcome_bonus_amount'),
            'min_spend' => RewardSettings::money('welcome_bonus_min_spend'),
            'status' => Coupon::LOCKED,
            'unlock_hint' => 'Share WiBuy on WhatsApp. It unlocks when a friend opens your link and signs up.',
            'expires_at' => $expires,
        ]);
    }

    /** A new person signed up from someone's link: unlock that person's bonus coupon and give points. */
    private function creditReferrer(User $newUser, ?string $referralCode, ?string $deviceHash): void
    {
        $referrer = $referralCode ? User::where('referral_code', Str::upper(trim($referralCode)))->first() : null;

        if (! $referrer || $referrer->id === $newUser->id) {
            return;
        }

        // One person with two accounts on the same phone does not count.
        if ($this->devices->shareADevice($referrer, $newUser)
            || ($deviceHash && $this->devices->otherAccountIds($deviceHash, $newUser) !== [])) {
            return;
        }

        $newUser->forceFill(['referred_by_id' => $referrer->id])->save();

        Coupon::where('user_id', $referrer->id)->where('source', 'welcome_bonus')->where('status', Coupon::LOCKED)
            ->update(['status' => Coupon::AVAILABLE, 'unlock_hint' => null]);

        $this->points->award($referrer, RewardSettings::int('referral_points'), 'A friend joined WiBuy from your link');
    }

    /** "Le X off your first order in the WiBuy app": once per person, only before their first order. */
    private function grantAppCoupon(User $user): void
    {
        if (Coupon::where('user_id', $user->id)->where('source', 'app_install')->exists() || $user->orders()->where('status', '!=', 'pending_payment')->exists()) {
            return;
        }

        $amount = RewardSettings::money('app_first_order_amount');

        if (! Money::gt($amount, 0)) {
            return;
        }

        $this->coupons->issue($user, [
            'title' => 'First order in the app',
            'source' => 'app_install',
            'amount' => $amount,
            'min_spend' => RewardSettings::money('app_first_order_min_spend'),
            'app_only' => true,
            'expires_at' => now()->addDays(30),
        ]);
    }
}
