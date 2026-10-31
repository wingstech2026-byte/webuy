<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Decides what is being bought: one product ("Buy now") or the whole cart.
 * Shared by the website checkout and the mobile API.
 */
class CheckoutLines
{
    public function __construct(private CartService $cart) {}

    /**
     * @return array{0: Collection<int, array{product: Product, quantity: int}>, 1: string} the lines and the source ("buy" or "cart")
     *
     * @throws MarketplaceException
     */
    public function resolve(User $user, ?string $productSlug = null, ?int $quantity = null, bool $sample = false): array
    {
        if ($sample) {
            $product = Product::where('slug', (string) $productSlug)->with(['seller.deliveryAreas', 'tiers', 'images'])->first();

            if (! $product || ! $product->isListed() || $product->sample_price === null) {
                throw new MarketplaceException('This product has no sample.');
            }

            // One unit at the sample price; the minimum order does not apply to samples.
            return [collect([['product' => $product, 'quantity' => 1, 'unit_price' => (string) $product->sample_price, 'is_sample' => true]]), 'buy'];
        }

        if (filled($productSlug)) {
            $product = Product::where('slug', $productSlug)->with(['seller.deliveryAreas', 'tiers', 'images'])->first();

            if (! $product || ! $product->isListed()) {
                throw new MarketplaceException('That product is not available.');
            }

            $quantity = max(1, (int) ($quantity ?? 1));

            if ($problem = $this->cart->problemFor($product, $quantity)) {
                throw new MarketplaceException("{$product->title}: {$problem}.");
            }

            return [collect([['product' => $product, 'quantity' => $quantity]]), 'buy'];
        }

        $lines = $this->cart->lines($user);

        if ($lines->isEmpty()) {
            throw new MarketplaceException('Your cart is empty.');
        }

        if ($bad = $lines->first(fn (array $line): bool => $line['problem'] !== null)) {
            throw new MarketplaceException("{$bad['product']->title}: {$bad['problem']}. Please fix your cart first.");
        }

        return [$lines->map(fn (array $line): array => ['product' => $line['product'], 'quantity' => $line['quantity']])->values(), 'cart'];
    }
}
