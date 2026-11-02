@extends('layouts.app')

@section('title', 'Checkout')

@section('content')
    <h1 class="mb-4 text-2xl font-extrabold">Checkout</h1>

    {{-- Step 1: where to deliver. Changing the district recalculates delivery fees (plain GET, no JavaScript). --}}
    <form method="GET" action="{{ route('checkout.show') }}" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
        @if ($source === 'buy')
            <input type="hidden" name="product" value="{{ $product->slug }}">
            <input type="hidden" name="qty" value="{{ $qty }}">
        @endif
        @if ($team) <input type="hidden" name="team" value="{{ $team->token }}"> @endif
        @if ($point && ! $team) <input type="hidden" name="latitude" value="{{ $point['lat'] }}"><input type="hidden" name="longitude" value="{{ $point['lng'] }}"> @endif
        <div class="min-w-56 flex-1">
            <label for="district-pick" class="label">Your district (for delivery fees)</label>
            <select id="district-pick" name="district" class="input">
                @foreach ($districts as $d)
                    <option value="{{ $d }}" @selected($district === $d)>{{ $d }}</option>
                @endforeach
            </select>
        </div>
        @if ($stations->isNotEmpty())
            <div class="min-w-56 flex-1">
                <label for="station-pick" class="label">WiBuy station where you would collect</label>
                <select id="station-pick" name="station_id" class="input">
                    <option value="">Choose a station…</option>
                    @foreach ($stations as $station)
                        <option value="{{ $station->id }}" @selected($stationId === $station->id)>{{ $station->name }} ({{ $station->district }})</option>
                    @endforeach
                </select>
            </div>
        @endif
        @if ($coupons->isNotEmpty())
            <div class="min-w-56 flex-1">
                <label for="coupon-pick" class="label">Coupon</label>
                <select id="coupon-pick" name="coupon_id" class="input">
                    <option value="">No coupon</option>
                    @foreach ($coupons as $coupon)
                        <option value="{{ $coupon->id }}" @selected($couponId === $coupon->id)>{{ $coupon->title }}: {{ $coupon->description }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <button class="btn btn-outline btn-sm">Update</button>
        @if ($summary['coupon_error']) <p class="field-error w-full">{{ $summary['coupon_error'] }}</p> @endif
    </form>

    <form method="POST" action="{{ route('checkout.store') }}" class="grid gap-6 lg:grid-cols-[1fr_22rem]" data-checkout data-recalc-url="{{ route('checkout.show') }}">
        @csrf
        <input type="hidden" name="source" value="{{ $source }}">
        <input type="hidden" name="district" value="{{ $district }}">
        <input type="hidden" name="station_id" value="{{ $stationId }}">
        @if ($summary['coupon'])
            <input type="hidden" name="coupon_id" value="{{ $summary['coupon']->id }}">
        @endif
        @if ($source === 'buy')
            <input type="hidden" name="product" value="{{ $product->slug }}">
            <input type="hidden" name="qty" value="{{ $qty }}">
        @endif

        @php($hasInstant = collect($summary['groups'])->contains(fn ($g) => $g['seller']->is_instant && $g['method'] === \App\Enums\DeliveryMethod::Delivery))
        @if ($team) <input type="hidden" name="team" value="{{ $team->token }}"> @endif
        @if ($point && ! $team) <input type="hidden" name="latitude" value="{{ $point['lat'] }}"><input type="hidden" name="longitude" value="{{ $point['lng'] }}"> @endif

        <div class="space-y-4">
            @if ($team)
                <section class="card border-brand-300 bg-brand-50 p-4">
                    <h2 class="font-bold">👥 {{ $team->title }}</h2>
                    <p class="text-sm">Delivered to {{ $team->place }}{{ $team->deliver_at ? ' at '.$team->deliver_at->format('H:i') : ', as soon as it is cooked' }}.
                        {{ $team->leader_id === $user->id ? 'You opened it: you pay the delivery once for everyone.' : 'The organiser pays the delivery: you pay only for your food.' }}</p>
                </section>
            @elseif ($hasInstant)
                <section class="card space-y-3 border-amber-300 bg-amber-50 p-4">
                    <h2 class="font-bold">⚡ Instant food delivery</h2>
                    @if ($point)
                        <p class="text-sm">📍 We have your location: the rider comes to this spot.
                            @foreach ($summary['groups'] as $group) @if ($group['seller']->is_instant && $group['distance_km'] !== null) {{ $group['seller']->shop_name }}: about {{ $group['seller']->instantEtaMinutes((float) $group['distance_km']) }} min. @endif @endforeach</p>
                    @else
                        <p class="text-sm">The rider needs your exact spot. Tap the button (your browser asks first).</p>
                        <button type="button" class="btn btn-primary btn-sm" data-locate="{{ request()->fullUrl() }}" data-lat-name="latitude" data-lng-name="longitude">📍 Use my location</button>
                    @endif
                    <div>
                        <label for="deliver_at" class="label">Deliver at a time (optional, e.g. lunch at 1:00 pm)</label>
                        <input id="deliver_at" type="datetime-local" name="deliver_at" class="input" value="{{ old('deliver_at') }}">
                        <p class="mt-1 text-xs text-stone-600">Empty: as soon as it is cooked. The shop starts cooking only after you have paid.</p>
                    </div>
                </section>
            @endif

            {{-- One block per seller: items and how to get them --}}
            @foreach ($summary['groups'] as $sellerId => $group)
                <section class="card p-4">
                    <h2 class="mb-2 font-bold">{{ $group['seller']->shop_name }} <span class="text-sm font-normal text-stone-500">&middot; {{ $group['seller']->location }}</span></h2>
                    <ul class="mb-3 space-y-1 text-sm">
                        @foreach ($group['items'] as $item)
                            <li class="flex justify-between gap-3">
                                <span>{{ $item['quantity'] }} &times; {{ $item['product']->title }} <span class="text-stone-500">@ {{ money($item['unit_price']) }}</span></span>
                                <span class="whitespace-nowrap font-semibold">{{ money($item['line_total']) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <fieldset class="space-y-2">
                        <legend class="label">How do you want to get it?</legend>
                        <label class="choice {{ $group['can_deliver'] ? '' : 'opacity-50' }}">
                            <input type="radio" name="methods[{{ $sellerId }}]" value="delivery" class="size-5 shrink-0 accent-brand-700"
                                   @checked($group['method']?->value === 'delivery') @disabled(! $group['can_deliver']) required>
                            <span>
                                <strong class="block">Delivery to {{ $district }}</strong>
                                <span class="text-sm text-stone-600">
                                    @if ($group['can_deliver'] && ! \App\Support\Money::isZero($group['shop_pays_delivery']))
                                        <span class="font-semibold text-brand-700">Free delivery (the shop pays {{ money($group['shop_pays_delivery']) }})</span>
                                    @elseif ($group['can_deliver'] && $group['free_delivery_from'])
                                        {{ money($group['district_fee']) }} &middot; free from {{ money($group['free_delivery_from']) }} in this shop
                                    @else
                                        {{ $group['can_deliver'] ? money($group['district_fee']) : 'This seller does not deliver to '.$district }}
                                    @endif
                                </span>
                            </span>
                        </label>
                        <label class="choice {{ $group['can_pickup'] ? '' : 'opacity-50' }}">
                            <input type="radio" name="methods[{{ $sellerId }}]" value="pickup" class="size-5 shrink-0 accent-brand-700"
                                   @checked($group['method']?->value === 'pickup') @disabled(! $group['can_pickup']) required>
                            <span>
                                <strong class="block">Pick up from the seller (free)</strong>
                                <span class="text-sm text-stone-600">{{ $group['can_pickup'] ? $group['seller']->pickup_address.', '.$group['seller']->location : 'This seller does not offer pickup' }}</span>
                            </span>
                        </label>
                        @if ($group['drop_station'])
                            {{-- The shop drops it at its nearest WiBuy station. --}}
                            <label class="choice {{ $summary['groups'][$sellerId]['collect_station'] ? '' : 'opacity-70' }}">
                                <input type="radio" name="methods[{{ $sellerId }}]" value="station" class="size-5 shrink-0 accent-brand-700"
                                       @checked($group['method']?->value === 'station') required>
                                <span>
                                    <strong class="block">📦 Collect at a WiBuy station &middot; {{ money($group['station_fee']) }}</strong>
                                    <span class="text-sm text-stone-600">
                                        @if ($group['collect_station'])
                                            {{ $group['collect_station']->label() }}. We text you a code when it arrives.
                                        @else
                                            Choose your station at the top of the page, then press Update.
                                        @endif
                                    </span>
                                </span>
                            </label>
                            <label class="choice">
                                <input type="radio" name="methods[{{ $sellerId }}]" value="station_door" class="size-5 shrink-0 accent-brand-700"
                                       @checked($group['method']?->value === 'station_door') required>
                                <span>
                                    <strong class="block">🛵 WiBuy brings it to my address &middot; {{ money($group['station_door_fee']) }}</strong>
                                    <span class="text-sm text-stone-600">A WiBuy rider brings it from the station near the shop. You give the rider the code from our SMS.</span>
                                </span>
                            </label>
                        @endif
                    </fieldset>
                    @error('methods.'.$sellerId) <p class="field-error">{{ $message }}</p> @enderror

                    <p class="mt-3 flex justify-between border-t border-stone-100 pt-2 text-sm">
                        <span>Order total for this seller</span><strong>{{ money($group['total']) }}</strong>
                    </p>
                </section>
            @endforeach

            @unless ($team)
            <section class="card space-y-3 p-4">
                <h2 class="font-bold">Your details</h2>
                <div>
                    <label for="recipient_name" class="label">Name</label>
                    <input id="recipient_name" name="recipient_name" autocomplete="name" required class="input" value="{{ old('recipient_name', $user->name) }}">
                    @error('recipient_name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="recipient_phone" class="label">Phone the seller can call</label>
                    <input id="recipient_phone" name="recipient_phone" type="tel" inputmode="tel" required class="input" value="{{ old('recipient_phone', phone_display($user->phone)) }}">
                    @error('recipient_phone') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="address" class="label">Delivery address (needed for delivery)</label>
                    <input id="address" name="address" autocomplete="street-address" class="input" value="{{ old('address') }}" placeholder="Street, area, landmark in {{ $district }}">
                    @error('address') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="note" class="label">Note for the sellers (optional)</label>
                    <input id="note" name="note" class="input" value="{{ old('note') }}" maxlength="200">
                </div>
            </section>

            @endunless

            <section class="card space-y-3 p-4">
                <h2 class="font-bold">Pay with mobile money</h2>
                <fieldset>
                    <legend class="sr-only">Operator</legend>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach ($operators as $operator)
                            <label class="choice justify-center font-bold">
                                <input type="radio" name="operator" value="{{ $operator->value }}" class="size-5 shrink-0 accent-brand-700" @checked(old('operator') === $operator->value) required>
                                {{ $operator->label() }}
                            </label>
                        @endforeach
                    </div>
                    @error('operator') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>
                <div>
                    <label for="payer_phone" class="label">Mobile money number</label>
                    <input id="payer_phone" name="payer_phone" type="tel" inputmode="tel" required class="input" value="{{ old('payer_phone', phone_display($user->phone)) }}">
                    <p class="mt-1 text-xs text-stone-500">We send one payment request to this number. You approve it on your phone with your PIN.</p>
                    @error('payer_phone') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </section>
        </div>

        <aside class="card h-fit space-y-2 p-4 lg:sticky lg:top-36">
            <div class="flex justify-between"><span>Items</span><span>{{ money($summary['items_total']) }}</span></div>
            <div class="flex justify-between"><span>Delivery</span><span>{{ money($summary['delivery_total']) }}</span></div>
            @if (! \App\Support\Money::isZero($summary['pass_savings']))
                <div class="flex justify-between text-brand-700"><span>WiBuy Pass free delivery</span><span>-{{ money($summary['pass_savings']) }}</span></div>
            @endif
            @if (! \App\Support\Money::isZero($summary['coupon_discount']))
                <div class="flex justify-between text-brand-700"><span>Coupon</span><span>-{{ money($summary['coupon_discount']) }}</span></div>
            @endif
            <div class="flex justify-between border-t border-stone-200 pt-2 text-lg"><strong>Total to pay</strong><strong class="text-brand-700">{{ money($summary['amount_due']) }}</strong></div>
            <p class="text-xs text-stone-500">Delivery shown for {{ $district }}. The total is always checked again when you pay.</p>
            <button class="btn btn-primary text-lg">Pay {{ money($summary['amount_due']) }}</button>
            <p class="text-xs text-stone-500">Your money is held safely by {{ config('wibuy.name') }}. Sellers are paid only after you confirm delivery.</p>
        </aside>
    </form>

    <script>
        // "Use my location": asked only when the buyer taps it; the point goes in the page address, nothing is tracked.
        document.querySelectorAll('[data-locate]').forEach((button) => button.addEventListener('click', () => {
            if (!navigator.geolocation) { alert('Your browser cannot share its location.'); return; }
            button.disabled = true; button.textContent = 'Finding you…';
            navigator.geolocation.getCurrentPosition((p) => {
                const url = new URL(button.dataset.locate, location.href);
                url.searchParams.set(button.dataset.latName || 'lat', p.coords.latitude.toFixed(6));
                url.searchParams.set(button.dataset.lngName || 'lng', p.coords.longitude.toFixed(6));
                location.href = url.toString();
            }, () => { button.disabled = false; button.textContent = 'Try again'; alert('Allow location in your browser, then try again.'); }, { enableHighAccuracy: true, timeout: 15000 });
        }));
    </script>
@endsection
