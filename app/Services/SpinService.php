<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Spin;
use App\Models\SpinPrize;
use App\Models\User;
use App\Support\RewardSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Daily spin wheel. Free to play (nobody pays to spin). One free spin a day, one more per successful
 * share up to the daily limit, plus WiBuy Pass extras. Prizes are points or coupons only, with the
 * odds the admin set, shown to everyone.
 */
class SpinService
{
    public function __construct(
        private ShareService $shares,
        private PointsService $points,
        private CouponService $coupons,
    ) {}

    /** @return Collection<int, array{id: int, label: string, type: string, odds_percent: float}> */
    public function prizesWithOdds(): Collection
    {
        $prizes = SpinPrize::active()->get();
        $total = max(1, $prizes->sum('weight'));

        return $prizes->map(fn (SpinPrize $prize) => [
            'id' => $prize->id,
            'label' => $prize->label,
            'type' => $prize->type,
            'odds_percent' => round($prize->weight * 100 / $total, 1),
        ])->values();
    }

    /** @return array{allowed: int, used: int, left: int, from_shares: int, from_pass: int} */
    public function allowance(User $user): array
    {
        $free = RewardSettings::int('spin_free_per_day');
        $max = RewardSettings::int('spin_max_per_day');
        $fromShares = max(0, min($this->shares->successfulToday($user), $max - $free));
        $fromPass = $user->hasPass() ? RewardSettings::int('spin_pass_extra') : 0;
        $allowed = min($free + $fromShares, $max) + $fromPass;
        $used = Spin::where('user_id', $user->id)->where('day', today()->toDateString())->count();

        return ['allowed' => $allowed, 'used' => $used, 'left' => max(0, $allowed - $used), 'from_shares' => $fromShares, 'from_pass' => $fromPass];
    }

    /** @throws MarketplaceException */
    public function spin(User $user): Spin
    {
        return DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->first(); // one spin at a time per person

            $allowance = $this->allowance($user);

            if ($allowance['left'] < 1) {
                throw new MarketplaceException('No spins left today. Share WiBuy with a friend for another spin, or come back tomorrow.');
            }

            $prize = $this->draw();

            if (! $prize) {
                throw new MarketplaceException('The spin wheel is closed for now.');
            }

            $source = $allowance['used'] < RewardSettings::int('spin_free_per_day') ? 'free'
                : ($allowance['used'] < $allowance['allowed'] - $allowance['from_pass'] ? 'share' : 'pass');

            $spin = Spin::create([
                'user_id' => $user->id,
                'spin_prize_id' => $prize->id,
                'day' => today()->toDateString(),
                'source' => $source,
                'prize_label' => $prize->label,
            ]);

            if ($prize->type === 'coupon' && $prize->coupon_amount !== null) {
                $this->coupons->issue($user, [
                    'title' => 'Spin prize',
                    'source' => 'spin',
                    'amount' => $prize->coupon_amount,
                    'min_spend' => $prize->coupon_min_spend,
                    'expires_at' => now()->addDays(max(1, $prize->coupon_days)),
                ]);
            } else {
                $this->points->award($user, $prize->points, 'Spin wheel prize');
            }

            return $spin->setRelation('prize', $prize);
        });
    }

    /** Pick a prize using the admin's weights. */
    private function draw(): ?SpinPrize
    {
        $prizes = SpinPrize::active()->get();
        $total = $prizes->sum('weight');

        if ($total < 1) {
            return null;
        }

        $ticket = random_int(1, $total);

        foreach ($prizes as $prize) {
            $ticket -= $prize->weight;

            if ($ticket <= 0) {
                return $prize;
            }
        }

        return $prizes->last();
    }
}
