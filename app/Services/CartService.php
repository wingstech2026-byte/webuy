<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Collection;

class CartService
{
    public function cart(User $user): Cart
    {
        return Cart::firstOrCreate(['user_id' => $user->id]);
    }

    /** @throws MarketplaceException */
    public function add(User $user, Product $product, int $quantity, ?int $variantId = null): CartItem
    {
        $product->loadMissing('seller', 'variants');

        if (! $product->isListed()) {
            throw new MarketplaceException('This product is not available.');
        }

        if ($product->seller->user_id === $user->id) {
            throw new MarketplaceException('You cannot buy your own products.');
        }

        if ($quantity < 1) {
            throw new MarketplaceException('Choose at least 1.');
        }

        $variant = $this->variantFor($product, $variantId);
        $item = CartItem::firstOrNew(['cart_id' => $this->cart($user)->id, 'product_id' => $product->id, 'variant_id' => $variant?->id]);
        $total = ($item->exists ? $item->quantity : 0) + $quantity;

        $this->assertQuantity($product, $total, $variant);

        $item->quantity = $total;
        $item->save();

        return $item;
    }

    /** @throws MarketplaceException */
    public function setQuantity(User $user, CartItem $item, int $quantity): ?CartItem
    {
        $this->assertOwns($user, $item);

        if ($quantity < 1) {
            $item->delete();

            return null;
        }

        $this->assertQuantity($item->product, $quantity, $item->variant);
        $item->update(['quantity' => $quantity]);

        return $item;
    }

    public function remove(User $user, CartItem $item): void
    {
        $this->assertOwns($user, $item);
        $item->delete();
    }

    public function removeProducts(User $user, iterable $productIds): void
    {
        CartItem::where('cart_id', $this->cart($user)->id)->whereIn('product_id', collect($productIds))->delete();
    }

    /** Number of units in the cart, for the header badge. */
    public function count(User $user): int
    {
        return (int) CartItem::whereHas('cart', fn ($q) => $q->where('user_id', $user->id))->sum('quantity');
    }

    /**
     * Cart lines with everything the page needs. "problem" explains why a line cannot be bought right now.
     *
     * @return Collection<int, array{item: CartItem, product: Product, quantity: int, problem: ?string}>
     */
    public function lines(User $user): Collection
    {
        return CartItem::where('cart_id', $this->cart($user)->id)
            ->with(['product.seller.deliveryAreas', 'product.seller.logo', 'product.tiers', 'product.images', 'product.variants', 'variant'])
            ->orderBy('id')->get()
            ->map(fn (CartItem $item) => [
                'item' => $item,
                'product' => $item->product,
                'variant' => $item->variant,
                'quantity' => $item->quantity,
                'problem' => $this->problemFor($item->product, $item->quantity, $item->variant),
            ]);
    }

    public function problemFor(Product $product, int $quantity, ?ProductVariant $variant = null): ?string
    {
        if (! $product->isListed()) {
            return 'No longer available';
        }

        if ($variant === null && $product->hasVariants()) {
            return 'Choose a size or option';
        }

        if ($variant !== null) {
            if (! $variant->is_active || $variant->product_id !== $product->id) {
                return 'This option is no longer available';
            }

            if ($variant->stock < 1) {
                return "{$variant->name} is out of stock";
            }

            if ($quantity > $variant->stock) {
                return "Only {$variant->stock} left of {$variant->name}";
            }
        }

        if ($product->stock < 1) {
            return 'Out of stock';
        }

        if ($quantity > $product->stock) {
            return "Only {$product->stock} left";
        }

        if ($quantity < $product->min_order_quantity) {
            return "Minimum order is {$product->min_order_quantity}";
        }

        return null;
    }

    /** @throws MarketplaceException */
    /**
     * The chosen variant: required when the product has active variants, refused when it has none.
     *
     * @throws MarketplaceException
     */
    public function variantFor(Product $product, ?int $variantId): ?ProductVariant
    {
        $product->loadMissing('variants');
        $active = $product->variants->where('is_active', true);

        if ($active->isEmpty()) {
            return null;
        }

        $variant = $active->firstWhere('id', $variantId);

        if (! $variant) {
            throw new MarketplaceException("Choose an option for {$product->title} (size, colour...).");
        }

        return $variant;
    }

    private function assertQuantity(Product $product, int $quantity, ?ProductVariant $variant = null): void
    {
        if ($variant !== null && $quantity > $variant->stock) {
            throw new MarketplaceException($variant->stock > 0
                ? "Only {$variant->stock} left of {$product->title} ({$variant->name})."
                : "{$product->title} ({$variant->name}) is out of stock.");
        }

        if ($quantity < $product->min_order_quantity) {
            throw new MarketplaceException("The minimum order for {$product->title} is {$product->min_order_quantity} {$product->unit}.");
        }

        if ($quantity > $product->stock) {
            throw new MarketplaceException($product->stock > 0
                ? "Only {$product->stock} left of {$product->title}."
                : "{$product->title} is out of stock.");
        }

        if ($quantity > config('wibuy.max_cart_quantity')) {
            throw new MarketplaceException('That is too many.');
        }
    }

    private function assertOwns(User $user, CartItem $item): void
    {
        if ($item->cart->user_id !== $user->id) {
            throw new MarketplaceException('That is not in your cart.');
        }
    }
}
