<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;

class CartService
{
    public function cart(User $user): Cart
    {
        return Cart::firstOrCreate(['user_id' => $user->id]);
    }

    /** @throws MarketplaceException */
    public function add(User $user, Product $product, int $quantity): CartItem
    {
        $product->loadMissing('seller');

        if (! $product->isListed()) {
            throw new MarketplaceException('This product is not available.');
        }

        if ($product->seller->user_id === $user->id) {
            throw new MarketplaceException('You cannot buy your own products.');
        }

        if ($quantity < 1) {
            throw new MarketplaceException('Choose at least 1.');
        }

        $item = CartItem::firstOrNew(['cart_id' => $this->cart($user)->id, 'product_id' => $product->id]);
        $total = ($item->exists ? $item->quantity : 0) + $quantity;

        $this->assertQuantity($product, $total);

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

        $this->assertQuantity($item->product, $quantity);
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
            ->with(['product.seller.deliveryAreas', 'product.seller.logo', 'product.tiers', 'product.images'])
            ->orderBy('id')->get()
            ->map(fn (CartItem $item) => [
                'item' => $item,
                'product' => $item->product,
                'quantity' => $item->quantity,
                'problem' => $this->problemFor($item->product, $item->quantity),
            ]);
    }

    public function problemFor(Product $product, int $quantity): ?string
    {
        if (! $product->isListed()) {
            return 'No longer available';
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
    private function assertQuantity(Product $product, int $quantity): void
    {
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
