<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Models\LiveSession;
use App\Services\LiveSellingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Live selling for buyers: who is live now, coming up and replays; watching, commenting and buying. */
class LiveController extends Controller
{
    public function index(): View
    {
        $with = fn ($q) => $q->with('seller')->withCount('products');

        return view('live.index', [
            'live' => $with(LiveSession::where('status', LiveSession::LIVE))->latest('started_at')->get(),
            'upcoming' => $with(LiveSession::where('status', LiveSession::SCHEDULED))->orderBy('starts_at')->limit(20)->get(),
            'replays' => $with(LiveSession::where('status', LiveSession::ENDED))->latest('ended_at')->limit(20)->get(),
        ]);
    }

    public function show(LiveSession $liveSession, LiveSellingService $live): View
    {
        return view('live.show', [
            'show' => $liveSession->load('seller', 'products.images', 'products.seller', 'products.tiers'),
            'feed' => $live->feed($liveSession),
        ]);
    }

    public function feed(Request $request, LiveSession $liveSession, LiveSellingService $live): JsonResponse
    {
        return response()->json($live->feed($liveSession, $request->integer('after')));
    }

    public function comment(Request $request, LiveSession $liveSession, LiveSellingService $live): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:200']]);

        try {
            $live->comment($liveSession, $request->user(), $data['body']);
        } catch (MarketplaceException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($live->feed($liveSession, $request->integer('after')), 201);
    }
}
