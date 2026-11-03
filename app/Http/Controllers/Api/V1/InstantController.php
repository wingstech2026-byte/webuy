<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\InstantController as WebInstantController;
use App\Http\Controllers\Seller\ShopController;
use App\Http\Controllers\TeamOrderController as WebTeamOrderController;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\SellerResource;
use App\Models\Product;
use App\Models\TeamOrder;
use App\Services\InstantService;
use App\Services\TeamOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The app's ⚡ Instant tab, office team orders, the rider's "I'm available" and the shop's instant settings. */
class InstantController extends Controller
{
    public function nearby(Request $request, InstantService $instant): JsonResponse
    {
        $data = $request->validate(['lat' => ['nullable', 'numeric', 'required_with:lng'], 'lng' => ['nullable', 'numeric', 'required_with:lat']]);
        $shops = isset($data['lat']) ? $instant->nearby((float) $data['lat'], (float) $data['lng']) : WebInstantController::allFoodShops();
        $products = WebInstantController::productsOf($shops);

        return response()->json(['data' => $shops->map(fn (array $row) => [
            'seller' => (new SellerResource($row['seller']))->resolve($request),
            'distance_km' => $row['distance_km'],
            'eta_minutes' => $row['eta_minutes'],
            'is_open' => $row['is_open'],
            'products' => ProductResource::collection($products->get($row['seller']->id, collect()))->resolve($request),
        ])->values(), 'top_rated' => ProductResource::collection($instant->topRated($shops))->resolve($request), 'close_options' => TeamOrderService::CLOSE_OPTIONS]);
    }

    public function startTeam(Request $request, TeamOrderService $teams): JsonResponse
    {
        $team = WebTeamOrderController::startFrom($request, $teams);

        return response()->json(['data' => $teams->present($team, $request->user())], 201);
    }

    public function team(Request $request, TeamOrder $teamOrder, TeamOrderService $teams): JsonResponse
    {
        return response()->json([
            'data' => $teams->present($teamOrder, $request->user('sanctum')), // public: signed in or not
            'products' => ProductResource::collection(Product::listed()->where('seller_id', $teamOrder->seller_id)->with('images', 'seller', 'tiers')->orderByDesc('sold_count')->limit(40)->get())->resolve($request),
        ]);
    }

    public function closeTeam(Request $request, TeamOrder $teamOrder, TeamOrderService $teams): JsonResponse
    {
        $teams->close($teamOrder, $request->user());

        return response()->json(['data' => $teams->present($teamOrder->refresh(), $request->user())]);
    }

    public function riderAvailable(Request $request, InstantService $instant): JsonResponse
    {
        $data = $request->validate(['lat' => ['nullable', 'numeric', 'required_with:lng'], 'lng' => ['nullable', 'numeric', 'required_with:lat']]);
        $instant->setAvailable($request->user(), isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null);
        $rider = $request->user()->refresh();

        return response()->json(['available_until' => $rider->isRiderAvailable() ? $rider->rider_available_until->toIso8601String() : null]);
    }

    public function shopSettings(Request $request): JsonResponse
    {
        $data = $request->validate(ShopController::instantRules());
        $seller = $request->user()->ownShop();
        abort_unless($seller, 403);

        if (($data['is_instant'] ?? false) && ! $seller->locations()->where('is_active', true)->exists()) {
            return response()->json(['message' => 'Add your shop on the map first, so riders and buyers nearby can find it.'], 422);
        }

        $seller->forceFill(ShopController::instantFields($data))->save();

        return response()->json(['data' => (new SellerResource($seller))->resolve($request)]);
    }
}
