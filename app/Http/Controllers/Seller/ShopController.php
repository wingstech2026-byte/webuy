<?php

namespace App\Http\Controllers\Seller;

use App\Enums\Operator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\DeliveryAreasRequest;
use App\Http\Requests\SellerApplicationRequest;
use App\Services\SellerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The shop profile and what the seller charges to deliver to each district. */
class ShopController extends Controller
{
    public function __construct(private SellerService $sellers) {}

    public function edit(Request $request)
    {
        return view('seller.shop', [
            'seller' => $request->user()->seller->load('logo', 'deliveryAreas', 'staff'),
            'districts' => config('wibuy.districts'),
            'operators' => Operator::cases(),
        ]);
    }

    /** Instant food delivery: on/off, opening hours and cooking time. Needs a shop location on the map. */
    public function instant(Request $request): RedirectResponse
    {
        $data = $request->validate(self::instantRules());
        $seller = $request->user()->ownShop();

        if (($data['is_instant'] ?? false) && ! $seller->locations()->where('is_active', true)->exists()) {
            return back()->withErrors(['is_instant' => 'Add your shop on the map first (Locations), so riders and buyers nearby can find it.']);
        }

        $seller->forceFill(self::instantFields($data))->save();

        return back()->with('status', $seller->is_instant ? 'Instant delivery is on. Buyers near you see your food in ⚡ Instant.' : 'Instant delivery is off.');
    }

    public static function instantRules(): array
    {
        return [
            'is_instant' => ['nullable', 'boolean'],
            'opens_at' => ['nullable', 'date_format:H:i', 'required_with:closes_at'],
            'closes_at' => ['nullable', 'date_format:H:i', 'required_with:opens_at', 'different:opens_at'],
            'prep_minutes' => ['required', 'integer', 'between:5,120'],
        ];
    }

    public static function instantFields(array $data): array
    {
        return [
            'is_instant' => (bool) ($data['is_instant'] ?? false),
            'opens_at' => $data['opens_at'] ?? null,
            'closes_at' => $data['closes_at'] ?? null,
            'prep_minutes' => (int) $data['prep_minutes'],
        ];
    }

    /** Holiday mode: closed until a date (empty: open). Products still show, but cannot be ordered. */
    public function holiday(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'holiday_until' => ['nullable', 'date', 'after_or_equal:today', 'before:+3 months'],
            'holiday_message' => ['nullable', 'string', 'max:200'],
        ], ['holiday_until.*' => 'Choose a date from today, within 3 months.']);

        $request->user()->seller->forceFill(['holiday_until' => $data['holiday_until'] ?? null, 'holiday_message' => $data['holiday_message'] ?? null])->save();

        return back()->with('status', isset($data['holiday_until']) ? 'Holiday mode is on. Buyers can look but not order until you are back.' : 'Your shop is open again.');
    }

    public function update(SellerApplicationRequest $request): RedirectResponse
    {
        $this->sellers->updateProfile(
            $request->user()->seller,
            collect($request->validated())->except('logo')->all(),
            $request->file('logo'),
        );

        return redirect()->route('seller.shop')->with('status', 'Shop saved.');
    }

    public function updateDelivery(DeliveryAreasRequest $request): RedirectResponse
    {
        $this->sellers->syncDeliveryAreas($request->user()->seller, $request->validated('fees', []) ?? []);

        return redirect()->route('seller.shop')->with('status', 'Delivery areas saved.');
    }
}
