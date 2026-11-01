<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "For you" and "People also bought". Built only from what happens on WiBuy: the products a shopper looked at
 * (kept per account; guests use their session list) and what they and other buyers paid for. No tracking
 * outside WiBuy, no location.
 */
class RecommendationService
{
    private const RELATIONS = ['seller', 'images', 'tiers'];

    /** A logged-in shopper opened a product. One row per person and product. */
    public function recordView(User $user, Product $product): void
    {
        DB::table('product_views')->upsert(
            [['user_id' => $user->id, 'product_id' => $product->id, 'viewed_at' => now()]],
            ['user_id', 'product_id'],
            ['viewed_at'],
        );
    }

    /**
     * Products in the categories the shopper cares about (looked at or bought), best sellers first,
     * leaving out what they already bought; topped up with popular products.
     *
     * @param  list<int>  $alsoViewed  product ids from the guest's session
     * @return Collection<int, Product>
     */
    public function forYou(?User $user, array $alsoViewed = [], int $limit = 12): Collection
    {
        $viewed = $user ? DB::table('product_views')->where('user_id', $user->id)->orderByDesc('viewed_at')->limit(30)->pluck('product_id')->all() : [];
        $bought = $user ? $this->boughtBy($user) : [];
        $seeds = array_values(array_unique([...$bought, ...$viewed, ...$alsoViewed]));

        if ($seeds === []) {
            return collect(); // nothing to go on yet
        }

        // Categories weighted: bought counts double.
        $weights = Product::whereIn('id', $seeds)->whereNotNull('category_id')->get(['id', 'category_id'])
            ->groupBy('category_id')
            ->map(fn (Collection $rows) => $rows->sum(fn (Product $p) => in_array($p->id, $bought, true) ? 2 : 1))
            ->sortDesc();

        $skip = [...$bought, ...$this->ownProducts($user)];
        $picked = collect();

        if ($weights->isNotEmpty()) {
            $picked = Product::listed()->with(self::RELATIONS)
                ->whereIn('category_id', $weights->keys()->take(6))
                ->whereNotIn('products.id', $skip ?: [0])
                ->orderByDesc('sold_count')->orderByDesc('rating_avg')->limit($limit * 3)->get()
                ->sortByDesc(fn (Product $p) => [$weights[$p->category_id] ?? 0, in_array($p->id, $viewed, true) ? 0 : 1, $p->sold_count])
                ->take($limit)->values();
        }

        if ($picked->count() < $limit) {
            $picked = $picked->concat(Product::listed()->with(self::RELATIONS)
                ->whereNotIn('products.id', [...$skip, ...$picked->pluck('id')] ?: [0])
                ->orderByDesc('sold_count')->orderByDesc('rating_avg')->orderByDesc('products.id')
                ->limit($limit - $picked->count())->get());
        }

        return $picked->values();
    }

    /**
     * What else the buyers of this product paid for, most buyers first.
     *
     * @return Collection<int, Product>
     */
    public function alsoBought(Product $product, int $limit = 8): Collection
    {
        $paid = [...OrderStatus::funded(), OrderStatus::Completed];
        $buyers = Order::whereIn('status', $paid)->whereHas('items', fn ($q) => $q->where('product_id', $product->id))->select('buyer_id');

        $ranked = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.buyer_id', $buyers)->whereIn('orders.status', $paid)
            ->where('order_items.product_id', '!=', $product->id)
            ->groupBy('order_items.product_id')
            ->orderByRaw('count(distinct orders.buyer_id) desc')
            ->limit($limit * 2)
            ->pluck('order_items.product_id');

        $products = Product::listed()->with(self::RELATIONS)->whereIn('products.id', $ranked)->get()->keyBy('id');

        return $ranked->map(fn ($id) => $products->get($id))->filter()->take($limit)->values();
    }

    /** @return list<int> */
    private function boughtBy(User $user): array
    {
        return OrderItem::whereHas('order', fn ($q) => $q->where('buyer_id', $user->id)->whereNotNull('paid_at'))
            ->distinct()->pluck('product_id')->all();
    }

    /** A seller is not shown their own shop's products. @return list<int> */
    private function ownProducts(?User $user): array
    {
        return $user?->seller ? $user->seller->products()->pluck('id')->all() : [];
    }
}
