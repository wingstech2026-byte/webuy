<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Checkout;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Coupons lower what the buyer pays; WiBuy pays the difference, so sellers always get their full price.
 * A coupon is reserved when the checkout is created, used when it is paid and freed if the payment fails.
 */
class CouponService
{
    public function issue(User $user, array $attributes): Coupon
    {
        return Coupon::create($attributes + ['user_id' => $user->id, 'status' => Coupon::AVAILABLE, 'min_spend' => '0.00']);
    }

    /**
     * Coupons this person can use now, best first.
     *
     * @return Collection<int, Coupon>
     */
    public function usable(User $user, string $channel = 'web'): Collection
    {
        return Coupon::usable()->where('user_id', $user->id)
            ->when($channel !== 'app', fn ($q) => $q->where('app_only', false))
            ->with('product')->orderByDesc('amount')->orderBy('expires_at')->get();
    }

    /**
     * How much this coupon takes off these items. Throws with a message people can read when it does not apply.
     *
     * @param  Collection<int, array{product: Product, quantity: int, unit_price: string, line_total: string}>  $items
     *
     * @throws MarketplaceException
     */
    public function discountFor(Coupon $coupon, User $buyer, Collection $items, string $channel): string
    {
        if ($coupon->user_id !== $buyer->id || ! $coupon->isUsable()) {
            throw new MarketplaceException('This coupon cannot be used. It may have expired or been used already.');
        }

        if ($coupon->app_only && $channel !== 'app') {
            throw new MarketplaceException('This coupon only works in the WiBuy app.');
        }

        if ($coupon->product_id) {
            $item = $items->first(fn ($item) => $item['product']->id === $coupon->product_id);

            if (! $item) {
                throw new MarketplaceException('This coupon is for '.($coupon->product?->title ?? 'another product').'. Add it to this order first.');
            }

            if (Money::cmp($item['line_total'], $coupon->min_spend) < 0) {
                throw new MarketplaceException('This coupon is for a bigger order of '.$item['product']->title.' (at least '.Money::format($coupon->min_spend).').');
            }

            // Product coupons (price cut, free trial, sample credit) cover one unit.
            return $this->capped($coupon, $item['unit_price']);
        }

        $itemsTotal = Money::sum($items->pluck('line_total'));

        if (Money::cmp($itemsTotal, $coupon->min_spend) < 0) {
            throw new MarketplaceException('This coupon needs items worth at least '.Money::format($coupon->min_spend).'.');
        }

        return $this->capped($coupon, $itemsTotal);
    }

    private function capped(Coupon $coupon, string $base): string
    {
        $discount = $coupon->percent !== null ? Money::percentOf($base, $coupon->percent) : Money::from($coupon->amount);

        if ($coupon->max_discount !== null && Money::cmp($discount, $coupon->max_discount) > 0) {
            $discount = Money::from($coupon->max_discount);
        }

        return Money::cmp($discount, $base) > 0 ? Money::from($base) : $discount;
    }

    public function reserve(Coupon $coupon, Checkout $checkout, string $discount): void
    {
        $coupon->forceFill(['status' => Coupon::RESERVED, 'checkout_id' => $checkout->id, 'discount_given' => $discount])->save();
    }

    public function markUsed(Checkout $checkout): void
    {
        Coupon::where('checkout_id', $checkout->id)->where('status', Coupon::RESERVED)
            ->update(['status' => Coupon::USED, 'used_at' => now()]);
    }

    /** The payment failed or timed out: the person can use the coupon again. */
    public function release(Checkout $checkout): void
    {
        Coupon::where('checkout_id', $checkout->id)->where('status', Coupon::RESERVED)
            ->update(['status' => Coupon::AVAILABLE, 'checkout_id' => null, 'discount_given' => null]);
    }

    /** Coupons past their date can no longer be used. */
    public function expireOld(): int
    {
        return Coupon::whereIn('status', [Coupon::AVAILABLE, Coupon::LOCKED])
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())
            ->update(['status' => Coupon::EXPIRED]);
    }
}
