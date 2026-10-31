<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FlashDealResource;
use App\Models\FlashDeal;
use App\Models\FlashDealReminder;
use App\Services\FarmService;
use App\Services\FlashDealService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** "Today's deals": lightning deals with countdowns and "Remind me". */
class FlashDealController extends Controller
{
    public function index(Request $request, FlashDealService $deals, FarmService $farms): AnonymousResourceCollection
    {
        // Public, but a logged-in person (Bearer token) also gets their reminders and a farm mission.
        $user = $request->user('sanctum');
        $today = $deals->today();

        FlashDealResource::$reminded = $user
            ? FlashDealReminder::where('user_id', $user->id)->whereIn('flash_deal_id', $today->pluck('id'))->pluck('flash_deal_id')->all()
            : [];

        if ($user) {
            $farms->mission($user, 'browse_deals');
        }

        return FlashDealResource::collection($today)->additional(['server_time' => now()->toIso8601String()]);
    }

    public function remind(Request $request, FlashDeal $flashDeal, FlashDealService $deals): JsonResponse
    {
        $on = $deals->toggleReminder($request->user(), $flashDeal);

        return response()->json([
            'reminded' => $on,
            'message' => $on ? 'We will send you an SMS when this deal starts.' : 'Reminder removed.',
        ]);
    }
}
