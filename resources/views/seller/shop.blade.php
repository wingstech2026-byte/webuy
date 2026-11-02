@extends('seller.layout')

@section('seller')
    <h1 class="mb-4 text-2xl font-extrabold">Shop</h1>

    <div class="grid gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('seller.shop.update') }}" enctype="multipart/form-data" class="card space-y-4 p-5">
            @csrf @method('PUT')
            <h2 class="font-bold">Shop profile</h2>
            @include('sell.fields', ['seller' => $seller])
            <button class="btn btn-primary">Save shop</button>
        </form>

        {{-- Instant food delivery --}}
        <form method="POST" action="{{ route('seller.shop.instant') }}" class="card space-y-3 p-5">
            @csrf @method('PUT')
            <h2 class="font-bold">⚡ Instant food delivery</h2>
            <p class="text-sm text-stone-600">For cooked food and quick goods: buyers near your shop (offices too) order and pay, you accept within 10 minutes and cook, then tap "Food ready" and the nearest WiBuy rider comes. Needs your shop on the map (Locations).</p>
            <label class="flex items-center gap-2"><input type="hidden" name="is_instant" value="0"><input type="checkbox" name="is_instant" value="1" class="size-5 accent-brand-700" @checked(old('is_instant', $seller->is_instant))> Offer instant delivery</label>
            @error('is_instant') <p class="field-error">{{ $message }}</p> @enderror
            <div class="grid grid-cols-3 gap-2">
                <div><label for="opens_at" class="label">Opens</label><input id="opens_at" type="time" name="opens_at" class="input" value="{{ old('opens_at', $seller->opens_at) }}"></div>
                <div><label for="closes_at" class="label">Closes</label><input id="closes_at" type="time" name="closes_at" class="input" value="{{ old('closes_at', $seller->closes_at) }}"></div>
                <div><label for="prep_minutes" class="label">Cooking (min)</label><input id="prep_minutes" type="number" min="5" max="120" name="prep_minutes" class="input" value="{{ old('prep_minutes', $seller->prep_minutes) }}"></div>
            </div>
            @error('closes_at') <p class="field-error">{{ $message }}</p> @enderror
            <button class="btn btn-outline btn-sm">Save</button>
        </form>

        {{-- Shop helpers --}}
        <div class="card space-y-3 p-5">
            <h2 class="font-bold">🧑‍🤝‍🧑 Shop helpers</h2>
            <p class="text-sm text-stone-600">People who help you run the shop: they accept, pack and ship orders, add products and answer questions. They cannot see your money, payouts or these settings.</p>
            @foreach ($seller->staff as $helper)
                <div class="flex items-center justify-between gap-2 text-sm">
                    <span>{{ $helper->name }} · {{ $helper->display_phone }}</span>
                    <form method="POST" action="{{ route('seller.staff.destroy', $helper) }}" onsubmit="return confirm('Remove this helper?')">@csrf @method('DELETE')<button class="font-semibold text-red-700">Remove</button></form>
                </div>
            @endforeach
            <form method="POST" action="{{ route('seller.staff.store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <div class="flex-1"><label for="staff_phone" class="label">Add a helper (their WiBuy phone number)</label>
                    <input id="staff_phone" name="phone" inputmode="tel" class="input" placeholder="076 123 456" required>
                    @error('phone') <p class="field-error">{{ $message }}</p> @enderror</div>
                <button class="btn btn-outline btn-sm">Add</button>
            </form>
        </div>

        {{-- Holiday mode --}}
        <form method="POST" action="{{ route('seller.shop.holiday') }}" class="card space-y-3 p-5">
            @csrf @method('PUT')
            <h2 class="font-bold">🏖 Holiday mode</h2>
            <p class="text-sm text-stone-600">Closed for some days? Buyers still see your products but cannot order until you are back, so no orders wait for you.</p>
            @if ($seller->isOnHoliday()) <p class="rounded-lg bg-amber-50 p-2 text-sm text-amber-900">{{ $seller->holidayNotice() }}</p> @endif
            <div><label for="holiday_until" class="label">Closed until (empty: open)</label>
                <input id="holiday_until" name="holiday_until" type="date" class="input" value="{{ old('holiday_until', $seller->holiday_until?->toDateString()) }}">
                @error('holiday_until') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div><label for="holiday_message" class="label">Message for buyers (optional)</label>
                <input id="holiday_message" name="holiday_message" maxlength="200" class="input" value="{{ old('holiday_message', $seller->holiday_message) }}" placeholder="Back after the family wedding"></div>
            <button class="btn btn-outline btn-sm">Save</button>
        </form>

        <form method="POST" action="{{ route('seller.shop.delivery') }}" class="card space-y-3 p-5">
            @csrf @method('PUT')
            <h2 class="font-bold">Delivery fees</h2>
            @unless ($seller->offers_delivery)
                <p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">You have delivery turned off, so buyers can only pick up. Turn on "I deliver" in your profile to use these.</p>
            @endunless
            <p class="text-sm text-stone-600">Enter what you charge to deliver to each district. Leave a district empty if you do not deliver there.</p>
            @php($fees = $seller->deliveryAreas->pluck('fee', 'district'))
            @error('fees.*') <p class="field-error">{{ $message }}</p> @enderror
            @foreach ($districts as $district)
                <div class="flex items-center gap-3">
                    <label for="fee-{{ $loop->index }}" class="flex-1 text-sm font-semibold">{{ $district }}</label>
                    <input id="fee-{{ $loop->index }}" name="fees[{{ $district }}]" type="number" step="0.01" min="0" inputmode="decimal"
                           class="input w-32" placeholder="—" value="{{ old('fees.'.$district, $fees[$district] ?? '') }}">
                </div>
            @endforeach
            <button class="btn btn-primary">Save delivery fees</button>
        </form>
    </div>
@endsection
