{{-- Shop fields, shared by the seller application and the shop profile page. --}}
<div>
    <label for="shop_name" class="label">Shop name</label>
    <input id="shop_name" name="shop_name" required maxlength="80" class="input" value="{{ old('shop_name', $seller?->shop_name) }}">
    @error('shop_name') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="logo" class="label">Logo (optional)</label>
    @if ($seller?->logo?->isReady())
        <img src="{{ $seller->logo->thumb_url }}" alt="Current logo" class="mb-2 size-16 rounded-full object-cover">
    @endif
    <input id="logo" name="logo" type="file" accept="image/jpeg,image/png,image/webp" class="input">
    @error('logo') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="description" class="label">What do you sell? (optional)</label>
    <textarea id="description" name="description" rows="3" maxlength="1000" class="input">{{ old('description', $seller?->description) }}</textarea>
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <div>
        <label for="district" class="label">District</label>
        <select id="district" name="district" required class="input">
            <option value="">Choose…</option>
            @foreach ($districts as $d)
                <option value="{{ $d }}" @selected(old('district', $seller?->district) === $d)>{{ $d }}</option>
            @endforeach
        </select>
        @error('district') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="area" class="label">Area / town</label>
        <input id="area" name="area" maxlength="80" class="input" placeholder="e.g. Kissy, Bo town" value="{{ old('area', $seller?->area) }}">
    </div>
</div>
<div>
    <label for="pickup_address" class="label">Pickup address (where buyers collect)</label>
    <input id="pickup_address" name="pickup_address" required maxlength="200" class="input" value="{{ old('pickup_address', $seller?->pickup_address) }}">
    @error('pickup_address') <p class="field-error">{{ $message }}</p> @enderror
</div>
<fieldset class="space-y-2">
    <legend class="label">How can buyers get their orders?</legend>
    <label class="choice"><input type="checkbox" name="offers_delivery" value="1" class="size-5 shrink-0 accent-brand-700" @checked(old('offers_delivery', $seller?->offers_delivery ?? true))> I deliver (set your fees per district after approval)</label>
    <label class="choice"><input type="checkbox" name="offers_pickup" value="1" class="size-5 shrink-0 accent-brand-700" @checked(old('offers_pickup', $seller?->offers_pickup ?? true))> Buyers can pick up from me</label>
    @error('offers_pickup') <p class="field-error">{{ $message }}</p> @enderror
</fieldset>
<fieldset>
    <legend class="label">Where should we pay you?</legend>
    <div class="grid grid-cols-2 gap-2">
        @foreach ($operators as $operator)
            <label class="choice justify-center font-bold">
                <input type="radio" name="payout_operator" value="{{ $operator->value }}" class="size-5 shrink-0 accent-brand-700" required
                       @checked(old('payout_operator', $seller?->payout_operator?->value) === $operator->value)> {{ $operator->label() }}
            </label>
        @endforeach
    </div>
    @error('payout_operator') <p class="field-error">{{ $message }}</p> @enderror
</fieldset>
<div>
    <label for="payout_phone" class="label">Mobile money number for payouts</label>
    <input id="payout_phone" name="payout_phone" type="tel" inputmode="tel" required class="input"
           value="{{ old('payout_phone', $seller ? phone_display($seller->payout_phone) : phone_display(auth()->user()->phone)) }}">
    @error('payout_phone') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="free_delivery_from" class="label">Free delivery for orders from (Le, optional)</label>
    <input id="free_delivery_from" name="free_delivery_from" type="number" step="0.01" min="1" inputmode="decimal" class="input"
           value="{{ old('free_delivery_from', $seller?->free_delivery_from) }}" placeholder="e.g. 500">
    <p class="mt-1 text-xs text-stone-500">Buyers who spend this much in your shop pay no delivery. You pay it (if a WiBuy rider delivers, the rider's fee comes from your earnings).</p>
    @error('free_delivery_from') <p class="field-error">{{ $message }}</p> @enderror
</div>
<div>
    <label for="business_registration_no" class="label">Business registration number (optional)</label>
    <input id="business_registration_no" name="business_registration_no" maxlength="60" class="input" value="{{ old('business_registration_no', $seller?->business_registration_no) }}">
    <p class="mt-1 text-xs text-stone-500">Registered businesses can get a "Verified" badge.</p>
</div>
