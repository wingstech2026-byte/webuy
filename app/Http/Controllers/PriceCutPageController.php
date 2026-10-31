<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Models\PriceCut;
use App\Services\DeviceService;
use App\Services\PriceCutService;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The page friends see when they open a "Help me cut the price" link on WhatsApp. */
class PriceCutPageController extends Controller
{
    public function show(PriceCut $priceCut): View
    {
        $priceCut->load('campaign.product.images', 'campaign.product.seller', 'helpers.helper', 'user');

        return view('price-cuts.show', ['cut' => $priceCut, 'campaign' => $priceCut->campaign]);
    }

    public function cut(Request $request, PriceCut $priceCut, PriceCutService $cuts, DeviceService $devices): RedirectResponse
    {
        $hash = $devices->hashFromRequest($request);
        $devices->record($request->user(), $hash);

        try {
            $amount = $cuts->cut($priceCut, $request->user(), $hash);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'You cut '.Money::format($amount).' for '.$priceCut->user->first_name.'. Thank you!');
    }
}
