<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WishlistController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::listed()
            ->whereIn('products.id', WishlistItem::where('user_id', $request->user()->id)->select('product_id'))
            ->with('seller', 'images', 'tiers')
            ->latest('id')
            ->paginate(24);

        return view('wishlist.index', ['products' => $products]);
    }

    /** Heart button: adds the product, or removes it if it is already there. */
    public function toggle(Request $request, Product $product): RedirectResponse
    {
        $existing = WishlistItem::where('user_id', $request->user()->id)->where('product_id', $product->id);

        if ($existing->exists()) {
            $existing->delete();

            return back()->with('status', 'Removed from your wishlist.');
        }

        WishlistItem::create(['user_id' => $request->user()->id, 'product_id' => $product->id]);

        return back()->with('status', 'Saved to your wishlist.');
    }
}
