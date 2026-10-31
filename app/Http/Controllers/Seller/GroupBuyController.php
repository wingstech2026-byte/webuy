<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\GroupBuyCampaign;
use App\Services\GroupBuyService;
use App\Support\GroupBuyOfferRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A seller's group-buy offers and how they perform. */
class GroupBuyController extends Controller
{
    public function index(Request $request, GroupBuyService $groups)
    {
        $seller = $request->user()->seller;
        $campaigns = GroupBuyCampaign::where('seller_id', $seller->id)->with('product')->latest('id')->get();

        return view('seller.group-buys', [
            'campaigns' => $campaigns,
            'stats' => $campaigns->mapWithKeys(fn ($c) => [$c->id => $groups->stats($c)]),
            'products' => $seller->products()->listed()->orderBy('title')->get(['slug', 'title', 'price']),
            'pickupPoints' => $seller->locations()->active()->where('pickup_enabled', true)->get(),
            'districts' => config('wibuy.districts'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        GroupBuyCampaign::create(GroupBuyOfferRules::validate($request, $request->user()->seller));

        return back()->with('status', 'Group offer created. Buyers can start groups now.');
    }

    public function toggle(Request $request, GroupBuyCampaign $groupBuyCampaign): RedirectResponse
    {
        abort_unless($groupBuyCampaign->seller_id === $request->user()->seller->id, 404);
        $groupBuyCampaign->update(['is_active' => ! $groupBuyCampaign->is_active]);

        return back()->with('status', $groupBuyCampaign->is_active ? 'Offer switched on.' : 'Offer switched off. Open groups still finish or expire normally.');
    }
}
