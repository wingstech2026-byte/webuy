<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PayoutStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReasonRequest;
use App\Models\Payout;
use App\Services\PayoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PayoutController extends Controller
{
    public function __construct(private PayoutService $payouts) {}

    public function index(Request $request)
    {
        $status = PayoutStatus::tryFrom((string) $request->query('status', 'requested'));

        $items = Payout::with('seller.user')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(25)->withQueryString();

        return view('admin.payouts.index', ['items' => $items, 'status' => $status]);
    }

    public function approve(Request $request, Payout $payout): RedirectResponse
    {
        try {
            $this->payouts->approve($payout, $request->user());
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Payout approved. It is being sent to the seller.');
    }

    public function reject(ReasonRequest $request, Payout $payout): RedirectResponse
    {
        try {
            $this->payouts->reject($payout, $request->user(), $request->validated('reason'));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Payout rejected. The money is back on the seller\'s balance.');
    }
}
