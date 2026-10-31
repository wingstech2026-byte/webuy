@extends('seller.layout')

@section('seller')
    <h1 class="mb-1 text-2xl font-extrabold">Shop locations and delivery</h1>
    <p class="mb-4 text-stone-600">Put your shop, warehouse or pickup point on the map so nearby buyers find you, and set how far you deliver and what it costs. Your delivery districts still work as before.</p>

    @foreach ($locations as $loc)
        <div class="card mb-2 flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
            <span>
                <strong>{{ $loc->public_name }}</strong> ({{ \App\Models\SellerLocation::TYPES[$loc->type] ?? $loc->type }}) &middot; {{ $loc->district }}
                @if ($loc->delivery_radius_km) &middot; delivers {{ $loc->delivery_radius_km }} km: {{ money($loc->base_fee) }} + {{ money($loc->per_km_fee) }}/km @endif
                @if ($loc->pickup_enabled) &middot; pickup @endif
                &middot; <span class="badge {{ $loc->verification_status === 'verified' ? 'bg-brand-50 text-brand-800' : 'bg-stone-100' }}">{{ $loc->verification_status === 'verified' ? 'Premises checked by WiBuy' : 'Not checked yet' }}</span>
                @unless ($loc->is_active) <span class="badge bg-stone-100">off</span> @endunless
            </span>
            <a class="btn btn-outline btn-sm" href="{{ route('seller.locations', ['edit' => $loc->id]) }}">Edit</a>
        </div>
    @endforeach

    @php($l = $edit)
    <form method="POST" action="{{ $l ? route('seller.locations.update', $l) : route('seller.locations.store') }}" class="card mt-4 grid max-w-3xl gap-3 p-4 sm:grid-cols-2">
        @csrf
        <h2 class="text-lg font-extrabold sm:col-span-2">{{ $l ? 'Edit '.$l->public_name : 'Add a location' }}</h2>

        <div class="sm:col-span-2">
            <div id="map" class="h-72 w-full rounded-xl bg-stone-100" role="application" aria-label="Map: tap to place the pin on your shop"></div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <button type="button" id="use-gps" class="btn btn-outline btn-sm">📍 Use my current location</button>
                <span id="gps-status" class="text-sm text-stone-600">Tap the map or drag the pin onto your shop.</span>
            </div>
            <p class="mt-1 text-xs text-stone-500">No map? Type the coordinates below (you can copy them from Google Maps). Map data © OpenStreetMap contributors.</p>
        </div>
        <div><label class="label" for="latitude">Latitude</label><input id="latitude" name="latitude" required inputmode="decimal" class="input" value="{{ old('latitude', $l?->latitude ?? '8.4844') }}">@error('latitude') <p class="field-error">{{ $message }}</p> @enderror</div>
        <div><label class="label" for="longitude">Longitude</label><input id="longitude" name="longitude" required inputmode="decimal" class="input" value="{{ old('longitude', $l?->longitude ?? '-13.2299') }}"></div>

        <div><label class="label" for="type">Type</label>
            <select id="type" name="type" class="input">@foreach (\App\Models\SellerLocation::TYPES as $k => $v) <option value="{{ $k }}" @selected(old('type', $l?->type) === $k)>{{ $v }}</option> @endforeach</select></div>
        <div><label class="label" for="public_name">Name buyers see</label><input id="public_name" name="public_name" required maxlength="80" class="input" value="{{ old('public_name', $l?->public_name ?? auth()->user()->seller->shop_name) }}"></div>
        <div><label class="label" for="district">District</label>
            <select id="district" name="district" class="input">@foreach ($districts as $d) <option value="{{ $d }}" @selected(old('district', $l?->district ?? auth()->user()->seller->district) === $d)>{{ $d }}</option> @endforeach</select></div>
        <div><label class="label" for="town">Town / area</label><input id="town" name="town" maxlength="80" class="input" value="{{ old('town', $l?->town) }}"></div>
        <div class="sm:col-span-2"><label class="label" for="public_address">Address or landmark buyers see</label><input id="public_address" name="public_address" maxlength="160" class="input" value="{{ old('public_address', $l?->public_address) }}" placeholder="Opposite the Total filling station, Wilkinson Road"></div>
        <label class="choice sm:col-span-2"><input type="checkbox" name="is_residence" value="1" class="size-5 accent-brand-700" @checked(old('is_residence', $l?->is_residence))> This is my home: show buyers only the area (about 1 km), never the exact place</label>

        <fieldset class="space-y-2 sm:col-span-2">
            <legend class="label">Delivery from here</legend>
            <label class="choice"><input type="checkbox" name="delivery_enabled" value="1" class="size-5 accent-brand-700" @checked(old('delivery_enabled', $l?->delivery_enabled ?? true))> I deliver from this place</label>
        </fieldset>
        <div><label class="label" for="delivery_radius_km">Deliver up to (km, empty = by district only)</label><input id="delivery_radius_km" name="delivery_radius_km" type="number" step="0.5" min="0.5" class="input" value="{{ old('delivery_radius_km', $l?->delivery_radius_km) }}"></div>
        <div><label class="label" for="min_order_value">Minimum order for delivery (Le)</label><input id="min_order_value" name="min_order_value" type="number" step="0.01" min="0" class="input" value="{{ old('min_order_value', $l?->min_order_value) }}"></div>
        <div><label class="label" for="base_fee">Delivery fee: start (Le)</label><input id="base_fee" name="base_fee" type="number" step="0.01" min="0" class="input" value="{{ old('base_fee', $l?->base_fee ?? 10) }}"></div>
        <div><label class="label" for="per_km_fee">plus per km (Le)</label><input id="per_km_fee" name="per_km_fee" type="number" step="0.01" min="0" class="input" value="{{ old('per_km_fee', $l?->per_km_fee ?? 2) }}"></div>
        <div><label class="label" for="delivery_days">Delivery days</label><input id="delivery_days" name="delivery_days" maxlength="60" class="input" value="{{ old('delivery_days', $l?->delivery_days) }}" placeholder="Mon-Sat"></div>
        <div><label class="label" for="delivery_window">Usual delivery time (estimate)</label><input id="delivery_window" name="delivery_window" maxlength="60" class="input" value="{{ old('delivery_window', $l?->delivery_window) }}" placeholder="Same day if ordered before 12pm"></div>

        <fieldset class="space-y-2 sm:col-span-2">
            <legend class="label">Pickup</legend>
            <label class="choice"><input type="checkbox" name="pickup_enabled" value="1" class="size-5 accent-brand-700" @checked(old('pickup_enabled', $l?->pickup_enabled))> Buyers (and group buys) can collect here</label>
            <input name="pickup_instructions" maxlength="300" class="input" value="{{ old('pickup_instructions', $l?->pickup_instructions) }}" placeholder="Open 8am-6pm. Ask for Musa at the counter.">
        </fieldset>
        <label class="choice sm:col-span-2"><input type="checkbox" name="is_active" value="1" class="size-5 accent-brand-700" @checked(old('is_active', $l?->is_active ?? true))> Active</label>
        <div class="sm:col-span-2"><button class="btn btn-primary">Save location</button></div>
    </form>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        // The form works without the map: if the map cannot load, the coordinate fields still do.
        (function () {
            if (!window.L) return;
            const lat = document.getElementById('latitude'), lng = document.getElementById('longitude'), status = document.getElementById('gps-status');
            const start = [parseFloat(lat.value) || 8.4844, parseFloat(lng.value) || -13.2299];
            const map = L.map('map').setView(start, 15);
            L.tileLayer(@json(config('services.maps.tiles')), { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
            const pin = L.marker(start, { draggable: true }).addTo(map);
            const put = (p) => { pin.setLatLng(p); lat.value = p.lat.toFixed(6); lng.value = p.lng.toFixed(6); };
            pin.on('dragend', () => put(pin.getLatLng()));
            map.on('click', (e) => put(e.latlng));
            [lat, lng].forEach((f) => f.addEventListener('change', () => { const p = L.latLng(parseFloat(lat.value), parseFloat(lng.value)); if (!isNaN(p.lat)) { pin.setLatLng(p); map.panTo(p); } }));
            // Location is asked for only when the seller taps the button.
            document.getElementById('use-gps').addEventListener('click', () => {
                if (!navigator.geolocation) { status.textContent = 'This browser cannot share location. Tap the map instead.'; return; }
                status.textContent = 'Finding you…';
                navigator.geolocation.getCurrentPosition(
                    (pos) => { put(L.latLng(pos.coords.latitude, pos.coords.longitude)); map.setView(pin.getLatLng(), 17); status.textContent = 'Found (about ' + Math.round(pos.coords.accuracy) + ' m). Drag the pin if it is not exact.'; },
                    () => { status.textContent = 'Location not available or not allowed. Tap the map or type the coordinates.'; },
                    { enableHighAccuracy: true, timeout: 15000 }
                );
            });
        })();
    </script>
@endsection
