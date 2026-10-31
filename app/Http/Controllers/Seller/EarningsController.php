<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\PayoutRequest;
use App\Services\LedgerService;
use App\Services\PayoutService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EarningsController extends Controller
{
    public function index(Request $request, LedgerService $ledger)
    {
        $seller = $request->user()->seller;

        return view('seller.earnings', [
            'seller' => $seller,
            'money' => $ledger->summary($seller),
            'entries' => $seller->ledgerEntries()->with('order')->latest('id')->paginate(15, ['*'], 'ledger')->withQueryString(),
            'payouts' => $seller->payouts()->paginate(10, ['*'], 'payouts')->withQueryString(),
            'minPayout' => SettingsService::minPayout(),
        ]);
    }

    public function requestPayout(PayoutRequest $request, PayoutService $payouts): RedirectResponse
    {
        try {
            $payout = $payouts->request($request->user()->seller, $request->validated('amount'));
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('seller.earnings')->with('status', 'Payout requested: '.money($payout->amount).'. We will check it and send it to your mobile money.');
    }
}
