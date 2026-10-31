<?php

namespace App\Http\Controllers\Seller;

use App\Enums\Operator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\DeliveryAreasRequest;
use App\Http\Requests\SellerApplicationRequest;
use App\Services\SellerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The shop profile and what the seller charges to deliver to each district. */
class ShopController extends Controller
{
    public function __construct(private SellerService $sellers) {}

    public function edit(Request $request)
    {
        return view('seller.shop', [
            'seller' => $request->user()->seller->load('logo', 'deliveryAreas'),
            'districts' => config('wibuy.districts'),
            'operators' => Operator::cases(),
        ]);
    }

    public function update(SellerApplicationRequest $request): RedirectResponse
    {
        $this->sellers->updateProfile(
            $request->user()->seller,
            collect($request->validated())->except('logo')->all(),
            $request->file('logo'),
        );

        return redirect()->route('seller.shop')->with('status', 'Shop saved.');
    }

    public function updateDelivery(DeliveryAreasRequest $request): RedirectResponse
    {
        $this->sellers->syncDeliveryAreas($request->user()->seller, $request->validated('fees', []) ?? []);

        return redirect()->route('seller.shop')->with('status', 'Delivery areas saved.');
    }
}
