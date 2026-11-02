<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Http\Requests\AddToCartRequest;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\CartService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private CartService $cart) {}

    public function index(Request $request)
    {
        $lines = $this->cart->lines($request->user());

        // Per seller: the items and their subtotal (delivery is added at checkout).
        $groups = $lines->groupBy(fn ($line) => $line['product']->seller_id)->map(function ($sellerLines) {
            $sellerLines = $sellerLines->map(function ($line) {
                $unit = $line['variant']?->price !== null ? Money::from($line['variant']->price) : $line['product']->unitPriceFor($line['quantity']);
                $unit = Money::add($unit, Money::sum($line['addons']->pluck('price'))); // extras on each dish

                return $line + ['unit_price' => $unit, 'line_total' => Money::mul($unit, $line['quantity'])];
            });

            return [
                'seller' => $sellerLines->first()['product']->seller,
                'lines' => $sellerLines,
                'subtotal' => Money::sum($sellerLines->pluck('line_total')),
            ];
        });

        return view('cart.index', [
            'groups' => $groups,
            'total' => Money::sum($groups->pluck('subtotal')),
            'hasProblems' => $lines->contains(fn ($line) => $line['problem'] !== null),
        ]);
    }

    /** "Add to cart", or "Buy now" which goes straight to checkout with just this product. */
    public function store(AddToCartRequest $request, Product $product): RedirectResponse
    {
        $quantity = (int) $request->validated('quantity');
        $variantId = $request->validated('variant') !== null ? (int) $request->validated('variant') : null;

        try {
            if ($request->boolean('buy_now')) {
                $product->loadMissing('seller');
                abort_unless($product->isListed(), 404);

                if ($product->seller->user_id === $request->user()->id) {
                    throw new MarketplaceException('You cannot buy your own products.');
                }

                if ($quantity < $product->min_order_quantity) {
                    throw new MarketplaceException("The minimum order for {$product->title} is {$product->min_order_quantity} {$product->unit}.");
                }

                if ($quantity > $product->stock) {
                    throw new MarketplaceException($product->stock > 0 ? "Only {$product->stock} left." : 'This product is out of stock.');
                }

                $variant = $this->cart->variantFor($product, $variantId);

                if ($problem = $this->cart->problemFor($product, $quantity, $variant)) {
                    throw new MarketplaceException("{$product->title}: {$problem}.");
                }

                return redirect()->route('checkout.show', array_filter(['product' => $product->slug, 'qty' => $quantity, 'variant' => $variant?->id, 'addons' => $request->validated('addons')]));
            }

            $this->cart->add($request->user(), $product, $quantity, $variantId, $request->validated('addons') ?? []);
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('cart.index')->with('status', "Added to your cart: {$product->title}");
    }

    public function update(Request $request, CartItem $item): RedirectResponse
    {
        $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:'.config('wibuy.max_cart_quantity')]]);

        try {
            $this->cart->setQuantity($request->user(), $item, (int) $request->input('quantity'));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(Request $request, CartItem $item): RedirectResponse
    {
        try {
            $this->cart->remove($request->user(), $item);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Removed from your cart.');
    }
}
