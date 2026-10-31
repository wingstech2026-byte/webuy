<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Farm;
use App\Models\FarmMission;
use App\Models\FarmReward;
use App\Models\FarmWatering;
use App\Models\Order;
use App\Models\RewardClaim;
use App\Models\User;
use App\Support\RewardSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * WiBuy Farm: missions water the plant; a fully grown plant gives the prize the admin chose
 * (a big coupon, or a real gift such as a small bag of rice delivered with the next order).
 */
class FarmService
{
    public function __construct(private CouponService $coupons, private DeviceService $devices) {}

    /** @throws MarketplaceException */
    public function plant(User $user, string $plant): Farm
    {
        if (! array_key_exists($plant, Farm::PLANTS)) {
            throw new MarketplaceException('Choose a rice plant or a mango tree.');
        }

        $farm = $user->farm;

        if ($farm && $farm->water > 0) {
            throw new MarketplaceException('Your plant is already growing. You can choose again after the harvest.');
        }

        return Farm::updateOrCreate(['user_id' => $user->id], [
            'plant' => $plant,
            'code' => $farm->code ?? Str::lower(Str::random(8)),
            'water' => 0,
            'target' => RewardSettings::int('farm_target'),
        ]);
    }

    /**
     * A mission was done (check-in, order, review...). Waters the person's plant up to the mission's
     * daily limit. Does nothing when they have no plant or the mission is off.
     */
    public function mission(User $user, string $key): int
    {
        $farm = Farm::where('user_id', $user->id)->first();
        $mission = FarmMission::where('key', $key)->where('is_active', true)->first();

        if (! $farm || ! $mission || $this->doneToday($user, $key) >= $mission->daily_limit) {
            return 0;
        }

        $this->water($farm, $user, $key, $mission->water);

        return $mission->water;
    }

    /**
     * Water a friend's plant from their shared link. Once per friend per day, never your own plant
     * and never from a device the friend uses. The helper's own plant gets water too.
     *
     * @throws MarketplaceException
     */
    public function waterFriend(User $actor, Farm $friendFarm, ?string $deviceHash): int
    {
        $mission = FarmMission::where('key', 'water_friend')->where('is_active', true)->first();

        if (! $mission) {
            throw new MarketplaceException('Watering friends is switched off for now.');
        }

        if ($friendFarm->user_id === $actor->id) {
            throw new MarketplaceException('This is your own plant. Share the link with a friend.');
        }

        $friend = $friendFarm->user;

        if ($this->devices->shareADevice($actor, $friend)
            || ($deviceHash && in_array($friend->id, $this->devices->otherAccountIds($deviceHash, $actor), true))) {
            throw new MarketplaceException('Friends must water from their own phone.');
        }

        $already = FarmWatering::where('farm_id', $friendFarm->id)->where('actor_id', $actor->id)
            ->where('mission_key', 'water_friend')->where('day', today()->toDateString())->exists();

        if ($already) {
            throw new MarketplaceException("You already watered {$friend->first_name}'s plant today. Come back tomorrow.");
        }

        if ($this->doneToday($actor, 'water_friend') >= $mission->daily_limit) {
            throw new MarketplaceException("You watered {$mission->daily_limit} friends today. Come back tomorrow.");
        }

        $this->water($friendFarm, $actor, 'water_friend', $mission->water);

        if ($own = Farm::where('user_id', $actor->id)->first()) {
            $this->water($own, $actor, 'water_friend_back', $mission->water);
        }

        return $mission->water;
    }

    private function water(Farm $farm, User $actor, string $key, int $amount): void
    {
        DB::transaction(function () use ($farm, $actor, $key, $amount) {
            $locked = Farm::lockForUpdate()->find($farm->id);

            FarmWatering::create([
                'farm_id' => $locked->id,
                'actor_id' => $actor->id,
                'mission_key' => $key,
                'water' => $amount,
                'day' => today()->toDateString(),
            ]);

            $locked->increment('water', $amount);

            if ($locked->isGrown()) {
                $this->harvest($locked);
            }
        });

        $farm->refresh();
    }

    /** The plant is fully grown: give the prize and start a new plant of the same kind. */
    private function harvest(Farm $farm): void
    {
        $reward = FarmReward::for($farm->plant);

        if ($reward) {
            $claim = RewardClaim::create([
                'user_id' => $farm->user_id,
                'farm_reward_id' => $reward->id,
                'type' => $reward->type,
                'description' => $reward->type === 'gift' ? ($reward->gift_description ?: $reward->label) : $reward->label,
                'cost' => $reward->cost,
                'status' => 'pending',
            ]);

            if ($reward->type === 'coupon') {
                $coupon = $this->coupons->issue($farm->user, [
                    'title' => 'WiBuy Farm harvest',
                    'source' => 'farm',
                    'amount' => $reward->coupon_amount,
                    'min_spend' => $reward->coupon_min_spend,
                    'expires_at' => now()->addDays(30),
                ]);
                $claim->forceFill(['coupon_id' => $coupon->id, 'status' => 'fulfilled', 'fulfilled_at' => now()])->save();
            }
        }

        $farm->forceFill(['water' => 0, 'harvests' => $farm->harvests + 1, 'target' => RewardSettings::int('farm_target')])->save();
    }

    /** Gifts the person won ride along with their next paid order; the admin delivers and ticks them. */
    public function attachGifts(Order $order): void
    {
        $claims = RewardClaim::where('user_id', $order->buyer_id)->where('type', 'gift')->where('status', 'pending')->get();

        if ($claims->isEmpty()) {
            return;
        }

        $order->forceFill(['gift_note' => 'WiBuy gift: '.$claims->pluck('description')->implode('; ')])->save();
        $claims->each(fn (RewardClaim $claim) => $claim->forceFill(['status' => 'attached', 'order_id' => $order->id])->save());
    }

    public function doneToday(User $user, string $key): int
    {
        return FarmWatering::where('actor_id', $user->id)->where('mission_key', $key)
            ->where('day', today()->toDateString())->count();
    }

    /**
     * Missions with what is left to do today, for the app.
     *
     * @return array<int, array<string, mixed>>
     */
    public function missionsFor(User $user): array
    {
        return FarmMission::where('is_active', true)->orderBy('sort_order')->get()
            ->map(fn (FarmMission $mission) => [
                'key' => $mission->key,
                'label' => $mission->label,
                'water' => $mission->water,
                'daily_limit' => $mission->daily_limit,
                'done_today' => min($mission->daily_limit, $this->doneToday($user, $mission->key)),
            ])->all();
    }
}
