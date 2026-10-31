<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SpinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Daily spin wheel: free to play, prizes are points or coupons, odds shown to everyone. */
class SpinController extends Controller
{
    public function show(Request $request, SpinService $spins): JsonResponse
    {
        return response()->json([
            'prizes' => $spins->prizesWithOdds(),
            'spins' => $spins->allowance($request->user()),
        ]);
    }

    public function spin(Request $request, SpinService $spins): JsonResponse
    {
        $spin = $spins->spin($request->user());

        return response()->json([
            'prize' => ['id' => $spin->spin_prize_id, 'label' => $spin->prize_label, 'type' => $spin->prize?->type],
            'message' => "You won {$spin->prize_label}!",
            'spins' => $spins->allowance($request->user()),
            'points' => $request->user()->refresh()->points_balance,
        ]);
    }
}
