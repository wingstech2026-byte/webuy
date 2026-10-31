{{-- Start ($group null) or join a group: quantity, delivery or collection, and mobile money. Needs $campaign. --}}
@php($user = auth()->user())
<form method="POST" action="{{ $group ? route('groups.join', $group) : route('group-buys.start', $campaign) }}" class="space-y-3">
    @csrf
    @error('general') <p class="field-error">{{ $message }}</p> @enderror
    <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="g-qty">How many? (up to {{ $campaign->max_quantity_each }})</label>
            <input id="g-qty" name="quantity" type="number" min="1" max="{{ $campaign->max_quantity_each }}" value="{{ old('quantity', 1) }}" required class="input">
            @error('quantity') <p class="field-error">{{ $message }}</p> @enderror</div>
        <div><label class="label" for="g-method">Get it by</label>
            <select id="g-method" name="method" class="input">
                <option value="pickup" @selected(old('method') === 'pickup')>{{ $campaign->collectionPoint ? 'Collect at '.$campaign->collectionPoint->public_name : 'Pick up at the shop' }}</option>
                @if ($campaign->product->seller->offers_delivery)
                    <option value="delivery" @selected(old('method') === 'delivery')>Delivery to me</option>
                @endif
            </select></div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="g-name">Your name</label><input id="g-name" name="recipient_name" required maxlength="80" class="input" value="{{ old('recipient_name', $user?->name) }}"></div>
        <div><label class="label" for="g-phone">Your phone</label><input id="g-phone" name="recipient_phone" required inputmode="tel" class="input" value="{{ old('recipient_phone', $user?->display_phone) }}">
            @error('recipient_phone') <p class="field-error">{{ $message }}</p> @enderror</div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="g-district">District (for delivery)</label>
            <select id="g-district" name="district" class="input"><option value="">—</option>
                @foreach (config('wibuy.districts') as $d) <option value="{{ $d }}" @selected(old('district') === $d)>{{ $d }}</option> @endforeach
            </select>
            @error('district') <p class="field-error">{{ $message }}</p> @enderror</div>
        <div><label class="label" for="g-address">Address or landmark (for delivery)</label><input id="g-address" name="address" maxlength="200" class="input" value="{{ old('address') }}">
            @error('address') <p class="field-error">{{ $message }}</p> @enderror</div>
    </div>
    <div class="grid grid-cols-2 gap-3">
        <div><label class="label" for="g-operator">Pay with</label>
            <select id="g-operator" name="operator" class="input">@foreach (\App\Enums\Operator::cases() as $op) <option value="{{ $op->value }}" @selected(old('operator') === $op->value)>{{ $op->label() }}</option> @endforeach</select></div>
        <div><label class="label" for="g-payer">Mobile money number</label><input id="g-payer" name="payer_phone" required inputmode="tel" class="input" value="{{ old('payer_phone', $user?->display_phone) }}">
            @error('payer_phone') <p class="field-error">{{ $message }}</p> @enderror</div>
    </div>
    <button class="btn btn-primary w-full">{{ $group ? 'Join and pay '.money($campaign->group_price).' each' : 'Start a group: '.money($campaign->group_price).' each' }}</button>
    <p class="text-xs text-stone-500">Delivery fees are added at the shop's normal rate. Your place counts once you approve the payment. If the group does not fill up in time, the money comes back to this number.</p>
</form>
