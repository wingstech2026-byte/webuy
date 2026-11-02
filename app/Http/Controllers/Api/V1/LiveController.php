<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\LiveSession;
use App\Services\LiveSellingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Live selling in the app: the list, one show (video link, products, comments) and commenting. */
class LiveController extends Controller
{
    private static function card(LiveSession $s): array
    {
        return [
            'id' => $s->id, 'title' => $s->title, 'status' => $s->status, 'shop_name' => $s->seller->shop_name,
            'starts_at' => $s->starts_at?->toIso8601String(), 'stream_url' => $s->stream_url,
        ];
    }

    public function index(): JsonResponse
    {
        $shows = LiveSession::with('seller')->orderByRaw("case status when 'live' then 0 when 'scheduled' then 1 else 2 end")->latest('id')->limit(40)->get();

        return response()->json(['data' => $shows->map(fn ($s) => self::card($s))->values()]);
    }

    public function show(Request $request, LiveSession $liveSession, LiveSellingService $live): JsonResponse
    {
        $liveSession->load('seller', 'products.images', 'products.seller', 'products.tiers');

        return response()->json([
            'data' => self::card($liveSession),
            'products' => ProductResource::collection($liveSession->products)->resolve($request),
            'feed' => $live->feed($liveSession, $request->integer('after')),
        ]);
    }

    public function comment(Request $request, LiveSession $liveSession, LiveSellingService $live): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:200']]);
        $live->comment($liveSession, $request->user(), $data['body']);

        return response()->json($live->feed($liveSession, $request->integer('after')), 201);
    }
}
