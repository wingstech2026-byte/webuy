<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Models\Seller;
use App\Services\FollowService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Follow a shop, and "Following": the news of the shops you follow. */
class FollowController extends Controller
{
    public function toggle(Request $request, Seller $seller, FollowService $follows): RedirectResponse
    {
        try {
            $following = $follows->toggle($request->user(), $seller);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', $following ? "You follow {$seller->shop_name}: their new products, offers and live shows show in Following." : "You no longer follow {$seller->shop_name}.");
    }

    public function index(Request $request, FollowService $follows): View
    {
        return view('following.index', $follows->feed($request->user()));
    }
}
