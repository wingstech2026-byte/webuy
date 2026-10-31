<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupBuy;
use App\Models\GroupBuyCampaign;
use App\Services\GroupBuyService;
use Illuminate\Http\RedirectResponse;

/** Watch group-buy offers and groups; switch off an offer that breaks the rules. */
class GroupBuyController extends Controller
{
    public function index(GroupBuyService $groups)
    {
        $campaigns = GroupBuyCampaign::with('product', 'seller')->latest('id')->paginate(30);

        return view('admin.group-buys', [
            'campaigns' => $campaigns,
            'stats' => $campaigns->getCollection()->mapWithKeys(fn ($c) => [$c->id => $groups->stats($c)]),
            'recent' => GroupBuy::with('campaign.product', 'leader')->latest('id')->limit(20)->get(),
        ]);
    }

    public function toggle(GroupBuyCampaign $groupBuyCampaign): RedirectResponse
    {
        $groupBuyCampaign->update(['is_active' => ! $groupBuyCampaign->is_active]);

        return back()->with('status', $groupBuyCampaign->is_active ? 'Offer switched on.' : 'Offer switched off. Open groups still finish or expire normally.');
    }
}
