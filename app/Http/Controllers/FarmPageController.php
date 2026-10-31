<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Models\Farm;
use App\Services\DeviceService;
use App\Services\FarmService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Water my plant": the page friends see from a WiBuy Farm link. */
class FarmPageController extends Controller
{
    public function show(string $code): View
    {
        return view('farm.friend', ['farm' => Farm::where('code', $code)->with('user')->firstOrFail()]);
    }

    public function water(Request $request, string $code, FarmService $farms, DeviceService $devices): RedirectResponse
    {
        $farm = Farm::where('code', $code)->with('user')->firstOrFail();
        $hash = $devices->hashFromRequest($request);
        $devices->record($request->user(), $hash);

        try {
            $water = $farms->waterFriend($request->user(), $farm, $hash);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', "You gave {$farm->user->first_name}'s plant {$water} water. Thank you!");
    }
}
