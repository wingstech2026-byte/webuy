<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Bundle;
use App\Models\Order;
use App\Models\Product;
use App\Models\Seller;
use App\Models\ShopOffer;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The shop's own offers on one shop's part of a checkout. All are paid by the shop: bundles and "buy N get 1
 * free" add up; then the better of the best "spend X save Y" offer and a code the buyer typed. Never more than
 * the items cost.
 */
class ShopOfferService
{
    /**
     * @param  Collection<int, array{product: Product, quantity: int, unit_price: string}>  $items
     * @return array{discount: string, labels: list<string>, offer_id: ?int, code_applied: bool, code_error: ?string}
     */
    public function apply(Seller $seller, Collection $items, string $subtotal, ?string $code, ?User $buyer): array
    {
        $labels = [];
        $discount = '0.00';
        $byProduct = $items->groupBy(fn (array $item) => $item['product']->id);
        $qty = fn (int $productId) => (int) ($byProduct->get($productId)?->sum('quantity') ?? 0);
        $price = fn (int $productId) => (string) $byProduct->get($productId)?->first()['unit_price'];

        // Bundles: each complete set costs the bundle price.
        foreach (Bundle::where('seller_id', $seller->id)->where('is_active', true)->with('products')->get() as $bundle) {
            if ($bundle->products->isEmpty()) {
                continue;
            }

            $sets = $bundle->products->min(fn ($p) => intdiv($qty($p->id), max(1, (int) $p->pivot->quantity)));

            if ($sets < 1) {
                continue;
            }

            $normal = Money::sum($bundle->products->map(fn ($p) => Money::mul($price($p->id), (int) $p->pivot->quantity)));
            $saving = Money::sub($normal, $bundle->price);

            if (Money::gt($saving, 0)) {
                $discount = Money::add($discount, Money::mul($saving, $sets));
                $labels[] = $bundle->title.($sets > 1 ? " ×{$sets}" : '');
            }
        }

        $offers = ShopOffer::where('seller_id', $seller->id)->running()->with('product')->get();

        // Buy N, get 1 more free: every (N + 1)th unit is free.
        foreach ($offers->where('kind', ShopOffer::BOGO) as $offer) {
            $free = intdiv($qty((int) $offer->product_id), (int) $offer->buy_quantity + 1);

            if ($free > 0) {
                $discount = Money::add($discount, Money::mul($price((int) $offer->product_id), $free));
                $labels[] = $offer->label;
            }
        }

        // The best automatic "spend X" offer, or the buyer's code when it saves more.
        $best = $offers->where('kind', ShopOffer::SPEND)->filter(fn (ShopOffer $o) => Money::gte($subtotal, $o->min_spend))
            ->sortByDesc(fn (ShopOffer $o) => (float) $o->valueFor($subtotal))->first();
        [$codeOffer, $codeError] = filled($code) ? $this->codeOffer($seller, (string) $code, $subtotal, $buyer) : [null, null];

        if ($codeOffer && (! $best || Money::gte($codeOffer->valueFor($subtotal), $best->valueFor($subtotal)))) {
            $best = $codeOffer;
        }

        if ($best) {
            $discount = Money::add($discount, $best->valueFor($subtotal));
            $labels[] = $best->label;
        }

        return [
            'discount' => Money::gt($discount, $subtotal) ? Money::from($subtotal) : $discount,
            'labels' => $labels,
            'offer_id' => $best?->id,
            'code_applied' => $codeOffer !== null && $best?->id === $codeOffer->id,
            'code_error' => $codeError,
        ];
    }

    /** @return array{0: ?ShopOffer, 1: ?string} the offer, or why the code does not work here */
    private function codeOffer(Seller $seller, string $code, string $subtotal, ?User $buyer): array
    {
        $offer = ShopOffer::where('seller_id', $seller->id)->where('kind', ShopOffer::CODE)->where('code', Str::upper(trim($code)))->first();

        if (! $offer) {
            return [null, null]; // maybe another shop's code
        }

        if (! ShopOffer::whereKey($offer->id)->running()->exists()) {
            return [null, 'This code has ended.'];
        }

        if (! Money::gte($subtotal, $offer->min_spend)) {
            return [null, 'This code is for orders over '.Money::format($offer->min_spend).' from '.$seller->shop_name.'.'];
        }

        $used = $buyer && Order::where('buyer_id', $buyer->id)->where('shop_offer_id', $offer->id)
            ->whereNotIn('status', [OrderStatus::PendingPayment, OrderStatus::Cancelled, OrderStatus::Rejected, OrderStatus::Refunded])->exists();

        return $used ? [null, 'You already used this code.'] : [$offer, null];
    }

    /** Offers shown on a product page: the shop's running offers that can apply, and bundles with this product. */
    public function forProduct(Product $product): array
    {
        return [
            'offers' => ShopOffer::where('seller_id', $product->seller_id)->running()->where('kind', '!=', ShopOffer::CODE)
                ->where(fn ($q) => $q->where('kind', ShopOffer::SPEND)->orWhere('product_id', $product->id))->with('product')->get(),
            'bundles' => Bundle::where('is_active', true)->whereHas('products', fn ($q) => $q->whereKey($product->id))->with('products')->get(),
        ];
    }
}
