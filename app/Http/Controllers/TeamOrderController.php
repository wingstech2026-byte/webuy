<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Models\Product;
use App\Models\Seller;
use App\Models\TeamOrder;
use App\Services\TeamOrderService;
use App\Support\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Office team orders on the website: open one, share the link, colleagues add their meals. */
class TeamOrderController extends Controller
{
    /** The rules for opening one (website and app). */
    public static function rules(): array
    {
        return [
            'shop' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:80'],
            'closes_in' => ['required', 'integer', Rule::in(TeamOrderService::CLOSE_OPTIONS)],
            'deliver_at' => ['nullable', 'date'],
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:20'],
            'district' => ['required', Rule::in(config('wibuy.districts'))],
            'address' => ['required', 'string', 'max:200'],
            'landmark' => ['nullable', 'string', 'max:160'],
            'building' => ['nullable', 'string', 'max:80'],
            'floor' => ['nullable', 'string', 'max:20'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
        ];
    }

    /** @throws MarketplaceException */
    public static function startFrom(Request $request, TeamOrderService $teams): TeamOrder
    {
        $data = $request->validate(self::rules(), ['latitude.required' => 'Share your location so the rider finds the office.']);
        $phone = PhoneNumber::normalize($data['phone']);

        if ($phone === null) {
            throw new MarketplaceException('Enter a Sierra Leone phone number the rider can call.');
        }

        return $teams->start(
            $request->user(),
            Seller::where('slug', $data['shop'])->firstOrFail(),
            ['phone' => $phone, 'latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude']] + $data,
            $data['title'],
            (int) $data['closes_in'],
            filled($data['deliver_at'] ?? null) ? Carbon::parse($data['deliver_at']) : null,
        );
    }

    public function store(Request $request, TeamOrderService $teams): RedirectResponse
    {
        try {
            $team = self::startFrom($request, $teams);
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('team.show', $team)->with('status', 'Office order open! Share this page with your colleagues. Add your own meal too: you pay the delivery once for everyone.');
    }

    public function show(Request $request, TeamOrder $teamOrder, TeamOrderService $teams): View
    {
        return view('team.show', [
            'team' => $teams->present($teamOrder, $request->user()),
            'model' => $teamOrder,
            'products' => Product::listed()->where('seller_id', $teamOrder->seller_id)->with('images', 'seller', 'tiers')->orderByDesc('sold_count')->limit(40)->get(),
        ]);
    }

    public function close(Request $request, TeamOrder $teamOrder, TeamOrderService $teams): RedirectResponse
    {
        try {
            $teams->close($teamOrder, $request->user());
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Closed. The shop cooks what was ordered.');
    }
}
