<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\PriceCut;
use App\Models\PriceCutCampaign;
use App\Models\PriceCutHelper;
use App\Models\User;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Help me cut the price". A buyer starts a cut on an eligible product and shares the link. Each new
 * friend who logs in cuts a small random amount until the floor price. Then the buyer gets a coupon for
 * the difference on one unit (WiBuy pays it). One cut per friend per product; no self-cuts; no cutting
 * from a device the buyer (or another account that already cut) uses.
 */
class PriceCutService
{
    public function __construct(private DeviceService $devices, private CouponService $coupons) {}

    /** @throws MarketplaceException */
    public function start(User $user, PriceCutCampaign $campaign): PriceCut
    {
        $product = $campaign->product;

        if (! $campaign->isOpen() || ! $product->isListed()) {
            throw new MarketplaceException('This price cut has ended.');
        }

        if ($product->seller->user_id === $user->id) {
            throw new MarketplaceException('You cannot cut the price of your own product.');
        }

        $existing = PriceCut::where('price_cut_campaign_id', $campaign->id)->where('user_id', $user->id)
            ->whereIn('status', [PriceCut::ACTIVE, PriceCut::REACHED])->where('expires_at', '>', now())->first();

        if ($existing) {
            return $existing;
        }

        $running = PriceCut::where('user_id', $user->id)->where('status', PriceCut::ACTIVE)->where('expires_at', '>', now())->count();

        if ($running >= RewardSettings::int('price_cut_max_active')) {
            throw new MarketplaceException('You already have '.$running.' price cuts running. Finish one first.');
        }

        $price = Money::from($product->price);

        return PriceCut::create([
            'price_cut_campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'token' => Str::lower(Str::random(10)),
            'start_price' => $price,
            'current_price' => $price,
            'status' => PriceCut::ACTIVE,
            'expires_at' => now()->addHours(max(1, $campaign->hours)),
        ]);
    }

    /**
     * A friend cuts the price. Returns the amount cut.
     *
     * @throws MarketplaceException
     */
    public function cut(PriceCut $priceCut, User $helper, ?string $deviceHash): string
    {
        if ($helper->id === $priceCut->user_id) {
            throw new MarketplaceException('You cannot cut your own price. Share the link with friends.');
        }

        $owner = $priceCut->user;

        if ($this->devices->shareADevice($helper, $owner)) {
            throw new MarketplaceException('Friends must cut from their own phone.');
        }

        if ($deviceHash) {
            $others = $this->devices->otherAccountIds($deviceHash, $helper);

            $deviceAlreadyCut = $others !== [] && PriceCutHelper::where('price_cut_campaign_id', $priceCut->price_cut_campaign_id)
                ->where(fn ($q) => $q->whereIn('helper_id', $others)->orWhere('device_hash', $deviceHash))->exists();

            if (in_array($owner->id, $others, true) || $deviceAlreadyCut) {
                throw new MarketplaceException('Only one account per phone can cut this price.');
            }
        }

        try {
            return DB::transaction(function () use ($priceCut, $helper, $deviceHash) {
                $locked = PriceCut::lockForUpdate()->find($priceCut->id);
                $campaign = $locked->campaign;

                if (! $locked->isActive()) {
                    throw new MarketplaceException($locked->status === PriceCut::REACHED
                        ? 'Thank you! This price is already at its lowest.'
                        : 'This price cut has ended.');
                }

                if (PriceCutHelper::where('price_cut_campaign_id', $campaign->id)->where('helper_id', $helper->id)->exists()) {
                    throw new MarketplaceException('You already cut the price of this product. Each friend can cut once.');
                }

                $room = Money::sub($locked->current_price, $campaign->floor_price);
                $amount = $this->randomCut($campaign->min_cut, $campaign->max_cut);
                $amount = Money::cmp($amount, $room) > 0 ? $room : $amount;

                PriceCutHelper::create([
                    'price_cut_id' => $locked->id,
                    'price_cut_campaign_id' => $campaign->id,
                    'helper_id' => $helper->id,
                    'device_hash' => $deviceHash,
                    'amount' => $amount,
                ]);

                $locked->forceFill(['current_price' => Money::sub($locked->current_price, $amount)])->save();

                if (Money::cmp($locked->current_price, $campaign->floor_price) <= 0) {
                    $this->reachFloor($locked);
                }

                return $amount;
            });
        } catch (UniqueConstraintViolationException) {
            throw new MarketplaceException('You already cut the price of this product. Each friend can cut once.');
        }
    }

    /** The floor is reached: a coupon for (start price - floor) on one unit of the product. */
    private function reachFloor(PriceCut $priceCut): void
    {
        $campaign = $priceCut->campaign;

        $coupon = $this->coupons->issue($priceCut->user, [
            'title' => 'Price cut: '.$campaign->product->title,
            'source' => 'price_cut',
            'amount' => Money::sub($priceCut->start_price, $campaign->floor_price),
            'product_id' => $campaign->product_id,
            'expires_at' => now()->addHours(48),
        ]);

        $priceCut->forceFill(['status' => PriceCut::REACHED, 'coupon_id' => $coupon->id])->save();
    }

    /** A random amount between min and max, to the cent. */
    private function randomCut(string $min, string $max): string
    {
        $low = (int) round((float) $min * 100);
        $high = max($low, (int) round((float) $max * 100));

        return Money::from(random_int($low, $high) / 100);
    }

    public function expireOld(): int
    {
        return PriceCut::where('status', PriceCut::ACTIVE)->where('expires_at', '<=', now())->update(['status' => PriceCut::EXPIRED]);
    }
}
