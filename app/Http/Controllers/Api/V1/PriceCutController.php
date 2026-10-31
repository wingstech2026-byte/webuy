<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PriceCutResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\PriceCut;
use App\Models\PriceCutCampaign;
use App\Services\DeviceService;
use App\Services\PriceCutService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Help me cut the price" in the app. */
class PriceCutController extends Controller
{
    private const RELATIONS = ['campaign.product.seller', 'campaign.product.images', 'campaign.product.tiers', 'helpers.helper', 'user'];

    /** Products you can cut, and your own cuts. */
    public function index(Request $request): JsonResponse
    {
        $campaigns = PriceCutCampaign::open()->whereHas('product', fn ($q) => $q->listed())
            ->with('product.seller', 'product.images', 'product.tiers')->latest('id')->limit(40)->get();

        $mine = PriceCut::where('user_id', $request->user()->id)->with(self::RELATIONS)->latest('id')->limit(20)->get();

        return response()->json([
            'campaigns' => $campaigns->map(fn (PriceCutCampaign $c) => [
                'id' => $c->id,
                'floor_price' => (string) $c->floor_price,
                'hours' => $c->hours,
                'saving' => Money::sub($c->product->price, $c->floor_price),
                'product' => (new ProductResource($c->product))->resolve($request),
            ]),
            'mine' => PriceCutResource::collection($mine)->resolve($request),
        ]);
    }

    public function store(Request $request, PriceCutService $cuts): PriceCutResource
    {
        $data = $request->validate(['campaign_id' => ['required', 'integer']]);
        $campaign = PriceCutCampaign::findOrFail($data['campaign_id']);

        return new PriceCutResource($cuts->start($request->user(), $campaign)->load(self::RELATIONS));
    }

    /** Anyone with the link can see it (that is how friends find it). */
    public function show(PriceCut $priceCut): PriceCutResource
    {
        return new PriceCutResource($priceCut->load(self::RELATIONS));
    }

    public function cut(Request $request, PriceCut $priceCut, PriceCutService $cuts, DeviceService $devices): JsonResponse
    {
        $user = $request->user();
        $hash = $devices->hashFromRequest($request);
        $devices->record($user, $hash);

        $amount = $cuts->cut($priceCut, $user, $hash);

        return response()->json([
            'amount' => $amount,
            'message' => 'You cut '.Money::format($amount).' for '.$priceCut->user->first_name.'!',
            'data' => (new PriceCutResource($priceCut->refresh()->load(self::RELATIONS)))->resolve($request),
        ]);
    }
}
