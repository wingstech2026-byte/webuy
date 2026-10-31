<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\Product;
use App\Models\WishlistItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WishlistController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return ProductResource::collection(
            Product::listed()
                ->whereIn('products.id', WishlistItem::where('user_id', $request->user()->id)->select('product_id'))
                ->with('seller', 'images', 'tiers')
                ->latest('id')
                ->paginate(40)
        );
    }

    /** Heart button: adds the product, or removes it if it is already saved. */
    public function toggle(Request $request, Product $product): JsonResponse
    {
        $existing = WishlistItem::where('user_id', $request->user()->id)->where('product_id', $product->id);

        if ($existing->exists()) {
            $existing->delete();

            return response()->json(['saved' => false]);
        }

        WishlistItem::create(['user_id' => $request->user()->id, 'product_id' => $product->id]);

        return response()->json(['saved' => true]);
    }
}
