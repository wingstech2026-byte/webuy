<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FarmResource;
use App\Models\Farm;
use App\Models\FarmReward;
use App\Models\RewardClaim;
use App\Services\DeviceService;
use App\Services\FarmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** WiBuy Farm in the app. */
class FarmController extends Controller
{
    public function show(Request $request, FarmService $farms): JsonResponse
    {
        $user = $request->user();
        $farm = $user->farm;

        return response()->json([
            'farm' => $farm ? (new FarmResource($farm->load('user')))->resolve($request) : null,
            'plants' => collect(Farm::PLANTS)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'reward' => FarmReward::for($key)?->label,
            ])->values(),
            'missions' => $farms->missionsFor($user),
            'prizes' => RewardClaim::where('user_id', $user->id)->latest('id')->limit(10)->get(['description', 'type', 'status', 'created_at']),
        ]);
    }

    public function plant(Request $request, FarmService $farms): FarmResource
    {
        $data = $request->validate(['plant' => ['required', Rule::in(array_keys(Farm::PLANTS))]]);

        return new FarmResource($farms->plant($request->user(), $data['plant'])->load('user'));
    }

    public function friend(string $code): FarmResource
    {
        return new FarmResource(Farm::where('code', $code)->with('user')->firstOrFail());
    }

    public function waterFriend(Request $request, string $code, FarmService $farms, DeviceService $devices): JsonResponse
    {
        $farm = Farm::where('code', $code)->with('user')->firstOrFail();
        $hash = $devices->hashFromRequest($request);
        $devices->record($request->user(), $hash);

        $water = $farms->waterFriend($request->user(), $farm, $hash);

        return response()->json([
            'message' => "You gave {$farm->user->first_name}'s plant {$water} water.",
            'data' => (new FarmResource($farm->refresh()->load('user')))->resolve($request),
        ]);
    }
}
