<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryJob;
use App\Models\User;
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
        ]);
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
