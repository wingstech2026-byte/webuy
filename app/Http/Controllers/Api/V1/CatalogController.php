<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Operator;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CategoryResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\ReviewResource;
use App\Http\Resources\Api\V1\SellerResource;
use App\Models\Product;
use App\Models\Seller;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Everything a buyer can browse without logging in. */
class CatalogController extends Controller
{
    public function __construct(private SearchService $search) {}

    /** The app's start screen, in one request (fewer round trips on slow data). */
    public function home(): JsonResponse
    {
        return response()->json([
            'categories' => CategoryResource::collection($this->search->categoryTree()),
            'deals' => ProductResource::collection($this->search->deals()),
            'featured' => ProductResource::collection($this->search->featured()),
            'new_arrivals' => ProductResource::collection($this->search->newArrivals()),
            'top_sellers' => SellerResource::collection($this->search->topSellers()),
        ]);
    }

    /** Settings the app needs: districts, mobile money operators, sort options and the brand text. */
    public function meta(): JsonResponse
    {
        return response()->json([
            'name' => config('wibuy.name'),
            'tagline' => config('wibuy.tagline'),
            'currency_symbol' => config('wibuy.currency_symbol'),
            'districts' => config('wibuy.districts'),
            'operators' => collect(Operator::cases())->map(fn (Operator $o): array => ['value' => $o->value, 'label' => $o->label()]),
            'sorts' => collect(SearchService::SORTS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
            'web_url' => url('/'),
            // Map images for the app's address and nearby-shop maps (attribution: OpenStreetMap contributors).
            'map_tiles' => config('services.maps.tiles'),
        ]);
    }

    public function categories(): AnonymousResourceCollection
    {
        return CategoryResource::collection($this->search->categoryTree());
    }

    /** Search with the same filters and sorting as the website. */
    public function products(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:80'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'district' => ['nullable', 'string', 'max:40'],
            'verified' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:'.implode(',', array_merge(array_keys(SearchService::SORTS), ['nearest']))],
            'near_lat' => ['nullable', 'numeric', 'required_with:near_lng'],
            'near_lng' => ['nullable', 'numeric', 'required_with:near_lat'],
            'within_km' => ['nullable', 'numeric', 'min:1', 'max:200'],
        ]);

        return ProductResource::collection($this->search->search($filters, 20));
    }

    public function product(Product $product): JsonResponse
    {
        abort_unless($product->isListed(), 404);

        $product->load(['seller.logo', 'category', 'images', 'tiers']);

        return response()->json([
            'data' => (new ProductResource($product))->detailed(),
            'reviews' => ReviewResource::collection($product->reviews()->with('buyer', 'images')->limit(10)->get()),
        ]);
    }

    public function shop(Seller $seller): JsonResponse
    {
        abort_unless($seller->isApproved(), 404);

        return response()->json([
            'data' => new SellerResource($seller->load('logo')),
            'products' => ProductResource::collection($this->search->search(['seller' => $seller->id], 40)),
        ]);
    }
}
