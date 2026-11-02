<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\SellerResource;
use App\Models\Seller;
use App\Services\FollowService;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Following shops and their feed, and "Invite friends", for the app. */
class FollowController extends Controller
{
    public function toggle(Request $request, Seller $seller, FollowService $follows): JsonResponse
    {
        return response()->json(['is_following' => $follows->toggle($request->user(), $seller), 'followers' => $seller->followers()->count()]);
    }

    public function feed(Request $request, FollowService $follows): JsonResponse
    {
        $feed = $follows->feed($request->user());

        return response()->json([
            'shops' => SellerResource::collection($feed['shops'])->resolve($request),
            'products' => ProductResource::collection($feed['products'])->resolve($request),
            'offers' => $feed['offers']->map(fn ($o) => ['shop' => $o->seller->shop_name, 'shop_slug' => $o->seller->slug, 'label' => $o->label])->values(),
            'live' => $feed['live']->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'status' => $s->status, 'shop' => $s->seller->shop_name])->values(),
        ]);
    }

    public function invite(Request $request, ReferralService $referrals): JsonResponse
    {
        return response()->json(['data' => $referrals->summary($request->user())]);
    }
}
