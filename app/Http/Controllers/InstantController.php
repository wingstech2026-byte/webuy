<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Models\Product;
use App\Models\Seller;
use App\Services\InstantService;
use App\Services\TeamOrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** "⚡ Instant": food shops that deliver to where you are in 30-60 minutes. */
class InstantController extends Controller
{
    public function index(Request $request, InstantService $instant): View
    {
        $data = $request->validate(['lat' => ['nullable', 'numeric', 'required_with:lng'], 'lng' => ['nullable', 'numeric', 'required_with:lat']]);
        $hasPoint = isset($data['lat']);

        try {
            $shops = $hasPoint ? $instant->nearby((float) $data['lat'], (float) $data['lng']) : self::allFoodShops();
        } catch (MarketplaceException $e) {
            return view('instant.index', ['shops' => collect(), 'products' => collect(), 'topRated' => collect(), 'point' => null, 'error' => $e->getMessage(), 'closeOptions' => TeamOrderService::CLOSE_OPTIONS]);
        }

        return view('instant.index', [
            'shops' => $shops,
            'products' => self::productsOf($shops),
            'topRated' => $instant->topRated($shops),
            'point' => $hasPoint ? ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']] : null,
            'error' => null,
            'closeOptions' => TeamOrderService::CLOSE_OPTIONS,
        ]);
    }

    /** Without a location: every instant food shop, open ones first (no distance or time). */
    public static function allFoodShops(): Collection
    {
        return Seller::approved()->where('is_instant', true)->with('logo')->get()
            ->map(fn (Seller $seller) => ['seller' => $seller, 'distance_km' => null, 'eta_minutes' => null, 'is_open' => $seller->isOpenAt() && ! $seller->isOnHoliday()])
            ->sortByDesc('is_open')->values();
    }

    /** A few dishes per shop, best sellers first. */
    public static function productsOf(Collection $shops): Collection
    {
        return Product::listed()->whereIn('seller_id', $shops->pluck('seller.id'))->with('images', 'seller', 'tiers')
            ->orderByDesc('sold_count')->limit(200)->get()->groupBy('seller_id')->map(fn ($rows) => $rows->take(6));
    }
}
