<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Spin;
use App\Models\SpinPrize;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Spin wheel prizes and their odds (weights). Users see the same odds in the app. */
class SpinPrizeController extends Controller
{
    public function index()
    {
        $prizes = SpinPrize::orderBy('sort_order')->orderBy('id')->get();
        $totalWeight = max(1, $prizes->where('is_active', true)->sum('weight'));

        return view('admin.rewards.spin', [
            'prizes' => $prizes,
            'totalWeight' => $totalWeight,
            'spinsToday' => Spin::where('day', today()->toDateString())->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        SpinPrize::create($this->validated($request));

        return back()->with('status', 'Prize added.');
    }

    public function update(Request $request, SpinPrize $spinPrize): RedirectResponse
    {
        $spinPrize->update($this->validated($request));

        return back()->with('status', 'Prize saved.');
    }

    public function destroy(SpinPrize $spinPrize): RedirectResponse
    {
        $spinPrize->delete();

        return back()->with('status', 'Prize removed.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'type' => ['required', 'in:points,coupon'],
            'points' => ['required_if:type,points', 'nullable', 'integer', 'min:0', 'max:100000'],
            'coupon_amount' => ['required_if:type,coupon', 'nullable', 'numeric', 'min:0.01', 'max:100000'],
            'coupon_min_spend' => ['nullable', 'numeric', 'min:0'],
            'coupon_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'weight' => ['required', 'integer', 'min:0', 'max:100000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        return [
            'label' => $data['label'],
            'type' => $data['type'],
            'points' => $data['type'] === 'points' ? (int) $data['points'] : 0,
            'coupon_amount' => $data['type'] === 'coupon' ? $data['coupon_amount'] : null,
            'coupon_min_spend' => $data['coupon_min_spend'] ?? 0,
            'coupon_days' => $data['coupon_days'] ?? 7,
            'weight' => $data['weight'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $request->boolean('is_active'),
        ];
    }
}
