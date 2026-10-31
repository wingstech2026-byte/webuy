<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * Per-visitor lists kept in the session (works without logging in): products recently looked at,
 * and up to 3 products to compare side by side.
 */
final class BrowsingHistory
{
    public const RECENT_MAX = 20;

    public const COMPARE_MAX = 3;

    public function __construct(private Session $session) {}

    public function viewed(Product $product): void
    {
        $ids = array_values(array_diff($this->ids('recently_viewed'), [$product->id]));
        array_unshift($ids, $product->id);
        $this->session->put('recently_viewed', array_slice($ids, 0, self::RECENT_MAX));
    }

    /** Listed products, most recent first, without the one being looked at now. */
    public function recent(?Product $except = null, int $limit = 12): Collection
    {
        $ids = array_values(array_diff($this->ids('recently_viewed'), [$except?->id]));

        return $this->listed(array_slice($ids, 0, $limit));
    }

    /** @return bool false when the compare list is already full */
    public function toggleCompare(Product $product): bool
    {
        $ids = $this->ids('compare');

        if (in_array($product->id, $ids, true)) {
            $this->session->put('compare', array_values(array_diff($ids, [$product->id])));

            return true;
        }

        if (count($ids) >= self::COMPARE_MAX) {
            return false;
        }

        $this->session->put('compare', [...$ids, $product->id]);

        return true;
    }

    public function comparing(Product $product): bool
    {
        return in_array($product->id, $this->ids('compare'), true);
    }

    public function compared(): Collection
    {
        return $this->listed($this->ids('compare'), ['seller.deliveryAreas', 'tiers', 'variants', 'category']);
    }

    public function clearCompare(): void
    {
        $this->session->forget('compare');
    }

    /** @return list<int> */
    private function ids(string $key): array
    {
        return array_values(array_map('intval', (array) $this->session->get($key, [])));
    }

    /** @param list<int> $ids */
    private function listed(array $ids, array $with = []): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $products = Product::listed()->whereIn('id', $ids)->with(array_merge(['seller', 'images'], $with))->get()->keyBy('id');

        return collect($ids)->map(fn ($id) => $products->get($id))->filter()->values();
    }
}
