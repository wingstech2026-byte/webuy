<?php

namespace App\Http\Controllers;

use App\Models\GroupBuy;
use App\Services\GroupBuyService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The invitation link people open from WhatsApp: progress, price and how to join (in the app). */
class GroupPageController extends Controller
{
    public function show(Request $request, GroupBuy $group, GroupBuyService $groups): View
    {
        $group->load('campaign.product.images', 'campaign.product.seller', 'campaign.collectionPoint');

        // A guest who taps "Log in to join" comes back to this group.
        if (! $request->user()) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return view('groups.show', ['group' => $group, 'info' => $groups->present($group, $request->user())]);
    }
}
