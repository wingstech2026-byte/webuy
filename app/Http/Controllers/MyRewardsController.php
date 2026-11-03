<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Services\MyRewardsService;
use App\Services\PointsService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "My rewards": points, coupons, history, and changing points into a coupon. */
class MyRewardsController extends Controller
{
    public function show(Request $request, MyRewardsService $rewards): View
    {
        return view('rewards.mine', $rewards->summary($request->user()));
    }

    public function exchange(Request $request, PointsService $points): RedirectResponse
    {
        try {
            $coupon = $points->exchangeForCoupon($request->user());
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'You got a '.Money::format($coupon->amount).' coupon for your next order.');
    }
}
