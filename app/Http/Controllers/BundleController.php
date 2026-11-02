<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Models\Bundle;
use App\Services\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Add all to cart": every item of a bundle; the bundle price is applied at checkout. */
class BundleController extends Controller
{
    public function addToCart(Request $request, Bundle $bundle, CartService $cart): RedirectResponse
    {
        abort_unless($bundle->is_active, 404);

        try {
            foreach ($bundle->products as $product) {
                $cart->add($request->user(), $product, (int) $product->pivot->quantity);
            }
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('cart.index')->with('status', "{$bundle->title} is in your cart: the bundle price is taken off at checkout.");
    }
}
