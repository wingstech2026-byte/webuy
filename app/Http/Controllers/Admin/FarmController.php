<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Farm;
use App\Models\FarmMission;
use App\Models\FarmReward;
use App\Models\RewardClaim;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** WiBuy Farm: missions (water each gives), prizes and what they cost, and gifts to deliver. */
class FarmController extends Controller
{
    public function index()
    {
        return view('admin.rewards.farm', [
            'missions' => FarmMission::orderBy('sort_order')->get(),
            'rewards' => FarmReward::latest('id')->get(),
            'claims' => RewardClaim::with('user', 'order')->latest('id')->paginate(30),
            'cost' => [
                'total' => RewardClaim::sum('cost'),
                'month' => RewardClaim::where('created_at', '>=', now()->startOfMonth())->sum('cost'),
                'to_deliver' => RewardClaim::where('type', 'gift')->whereIn('status', ['pending', 'attached'])->count(),
            ],
            'farms' => ['count' => Farm::count(), 'harvests' => Farm::sum('harvests')],
        ]);
    }

    public function updateMission(Request $request, FarmMission $farmMission): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'water' => ['required', 'integer', 'min:0', 'max:10000'],
            'daily_limit' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $farmMission->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', 'Mission saved.');
    }

    public function storeReward(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'plant' => ['required', Rule::in(array_keys(Farm::PLANTS))],
            'label' => ['required', 'string', 'max:80'],
            'type' => ['required', 'in:coupon,gift'],
            'coupon_amount' => ['required_if:type,coupon', 'nullable', 'numeric', 'min:0.01'],
            'coupon_min_spend' => ['nullable', 'numeric', 'min:0'],
            'gift_description' => ['required_if:type,gift', 'nullable', 'string', 'max:200'],
            'cost' => ['required', 'numeric', 'min:0'],
        ]);

        // One active prize per plant: the newest one wins.
        FarmReward::where('plant', $data['plant'])->update(['is_active' => false]);
        FarmReward::create($data + ['coupon_min_spend' => $data['coupon_min_spend'] ?? 0, 'is_active' => true]);

        return back()->with('status', 'Prize saved. It is given to the next plants that finish growing.');
    }

    public function fulfil(RewardClaim $rewardClaim): RedirectResponse
    {
        $rewardClaim->update(['status' => 'fulfilled', 'fulfilled_at' => now()]);

        return back()->with('status', 'Marked as delivered.');
    }
}
