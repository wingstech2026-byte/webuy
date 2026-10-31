<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;

/** Commission and minimum payout. New values apply to new orders and new payout requests. */
class SettingsController extends Controller
{
    public function edit()
    {
        return view('admin.settings', [
            'commission' => SettingsService::commissionPercent(),
            'minPayout' => SettingsService::minPayout(),
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        Setting::put('commission_percent', $request->validated('commission_percent'));
        Setting::put('min_payout', $request->validated('min_payout'));

        return redirect()->route('admin.settings')->with('status', 'Settings saved. They apply to new orders and payout requests.');
    }
}
