<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\LocationRequest;
use App\Models\SellerLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Shop, warehouse and pickup point locations on a map, with delivery radius and fees. */
class LocationController extends Controller
{
    public function index(Request $request)
    {
        $seller = $request->user()->seller;

        return view('seller.locations', [
            'locations' => $seller->locations()->latest('id')->get(),
            'edit' => $request->query('edit') ? $seller->locations()->find($request->query('edit')) : null,
            'districts' => config('wibuy.districts'),
        ]);
    }

    public function save(LocationRequest $request, ?SellerLocation $location = null): RedirectResponse
    {
        $seller = $request->user()->seller;
        abort_if($location && $location->seller_id !== $seller->id, 404);

        $location ??= new SellerLocation(['seller_id' => $seller->id]);
        $location->fill($request->locationData())->save();

        return redirect()->route('seller.locations')->with('status', 'Location saved. Buyers near it can now find your shop.');
    }
}
