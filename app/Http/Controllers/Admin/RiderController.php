<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LedgerType;
use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\DeliveryJob;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Who may deliver for WiBuy, and every delivery with its proof. */
class RiderController extends Controller
{
    public function index(): View
    {
        return view('admin.riders', [
            'riders' => User::where('is_rider', true)->withCount(['deliveries as delivered_count' => fn ($q) => $q->where('status', DeliveryJob::DELIVERED)])->orderBy('name')->get(),
            'jobs' => DeliveryJob::with('order', 'rider', 'seller', 'photos')->latest('id')->paginate(30),
            // Delivered, then refunded: the rider did the work but earned nothing. Finance can pay the fee.
            'paidOrderIds' => LedgerEntry::whereNotNull('rider_id')->where('type', LedgerType::DeliveryFee)->pluck('order_id')->flip(),
        ]);
    }

    /** Pay the rider for a delivery whose order was refunded afterwards (Finance: it is a payment). */
    public function payFee(DeliveryJob $deliveryJob, LedgerService $ledger): RedirectResponse
    {
        try {
            $entry = $ledger->payRiderForRefundedOrder($deliveryJob->load('order', 'rider'));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Paid '.money($entry->amount)." to {$deliveryJob->rider->name}'s balance.");
    }

    public function toggle(Request $request): RedirectResponse
    {
        $phone = PhoneNumber::normalize((string) $request->input('phone'));
        $user = $phone ? User::where('phone', $phone)->first() : null;

        if (! $user) {
            throw ValidationException::withMessages(['phone' => 'Nobody has a WiBuy account with this number. Ask them to log in once first.']);
        }

        if ($user->is_rider && DeliveryJob::where('rider_id', $user->id)->whereIn('status', [DeliveryJob::ASSIGNED, DeliveryJob::PICKED_UP])->exists()) {
            return back()->withErrors(['phone' => "{$user->name} still has deliveries in progress."]);
        }

        $user->forceFill(['is_rider' => ! $user->is_rider])->save();

        return back()->with('status', $user->is_rider ? "{$user->name} is now a WiBuy rider." : "{$user->name} is no longer a rider.");
    }
}
