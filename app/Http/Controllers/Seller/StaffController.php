<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ShopStaffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The owner adds and removes shop helpers. */
class StaffController extends Controller
{
    public function store(Request $request, ShopStaffService $staff): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);

        try {
            $helper = $staff->add($request->user()->ownShop(), $data['phone']);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['phone' => $e->getMessage()]);
        }

        return back()->with('status', "{$helper->name} is now a helper in your shop.");
    }

    public function destroy(Request $request, User $user, ShopStaffService $staff): RedirectResponse
    {
        $staff->remove($request->user()->ownShop(), $user);

        return back()->with('status', 'Helper removed.');
    }
}
