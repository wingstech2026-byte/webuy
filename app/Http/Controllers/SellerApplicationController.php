<?php

namespace App\Http\Controllers;

use App\Enums\Operator;
use App\Enums\SellerStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Requests\SellerApplicationRequest;
use App\Services\SellerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Any buyer can apply to open a shop. An admin approves it. */
class SellerApplicationController extends Controller
{
    public function create(Request $request)
    {
        $seller = $request->user()->seller;

        return view('sell.apply', [
            'seller' => $seller?->load('logo'),
            'districts' => config('wibuy.districts'),
            'operators' => Operator::cases(),
        ]);
    }

    public function store(SellerApplicationRequest $request, SellerService $sellers): RedirectResponse
    {
        $user = $request->user();
        $data = collect($request->validated())->except('logo')->all();

        try {
            $existing = $user->seller()->first();

            if ($existing && $existing->status === SellerStatus::Rejected) {
                $sellers->reapply($existing, $data, $request->file('logo'));
            } else {
                $sellers->apply($user, $data, $request->file('logo'));
            }
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('sell.apply')
            ->with('status', 'Thank you! We will check your application and text you when it is approved.');
    }
}
