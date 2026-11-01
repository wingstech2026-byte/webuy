<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Partner shops that run WiBuy pickup stations: add one (by the partner's phone number), switch it on or off. */
class StationController extends Controller
{
    public function index(): View
    {
        return view('admin.stations', ['stations' => Station::with('partner')->orderBy('district')->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:200'],
            'district' => ['required', Rule::in(config('wibuy.districts'))],
            'latitude' => ['required', 'numeric', 'between:6.8,10.1'],   // Sierra Leone
            'longitude' => ['required', 'numeric', 'between:-13.4,-10.2'],
            'opening_hours' => ['nullable', 'string', 'max:120'],
        ], ['latitude.between' => 'That point is not in Sierra Leone.', 'longitude.between' => 'That point is not in Sierra Leone.']);

        $phone = PhoneNumber::normalize($data['phone']);
        $partner = $phone ? User::where('phone', $phone)->first() : null;

        if (! $partner) {
            throw ValidationException::withMessages(['phone' => 'Nobody has a WiBuy account with this number. Ask the partner to log in once first.']);
        }

        if (Station::where('user_id', $partner->id)->exists()) {
            throw ValidationException::withMessages(['phone' => "{$partner->name} already runs a station."]);
        }

        Station::create(['user_id' => $partner->id] + collect($data)->except('phone')->all());

        return back()->with('status', "{$data['name']} is now a WiBuy station, run by {$partner->name}.");
    }

    public function toggle(Station $station): RedirectResponse
    {
        $station->forceFill(['is_active' => ! $station->is_active])->save();

        return back()->with('status', $station->is_active ? "{$station->name} is open for parcels." : "{$station->name} is switched off: no new orders will use it.");
    }
}
