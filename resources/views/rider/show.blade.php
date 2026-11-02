@extends('layouts.app')

@section('title', 'Delivery')

@section('content')
    <a href="{{ route('rider.index') }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; Rider jobs</a>

    <div class="card mx-auto max-w-xl space-y-3 p-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-xl font-extrabold">{{ $job['order_number'] ?? 'Delivery job' }}</h1>
            <span class="badge bg-brand-50 text-brand-800">{{ $job['status_label'] }}</span>
        </div>
        @if ($job['is_instant']) <p class="rounded-lg bg-amber-50 p-2 font-semibold text-amber-900">⚡ Hot food: deliver it straight away{{ $job['deliver_at'] ? ' (expected at '.\Illuminate\Support\Carbon::parse($job['deliver_at'])->format('H:i').')' : '' }}.</p> @endif
        <p><strong>Pay:</strong> {{ money($job['fee']) }}</p>
        <p><strong>Collect at:</strong> {{ $job['pickup_name'] }}, {{ $job['pickup_address'] }}
            @if ($job['pickup_latitude']) &middot; <a class="text-brand-700 underline" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $job['pickup_latitude'] }},{{ $job['pickup_longitude'] }}">map</a> @endif</p>

        @if ($job['dropoff_station'])
            <p><strong>Deliver to WiBuy station:</strong> {{ $job['dropoff_station']['name'] }}, {{ $job['dropoff_station']['address'] }} ({{ $job['dropoff_station']['district'] }})</p>
            <p class="text-sm text-stone-600">The staff there confirm they received it.</p>
        @elseif ($job['is_mine'])
            <p><strong>Deliver to:</strong> {{ $job['buyer_name'] }}, <a class="text-brand-700" href="tel:+{{ $job['buyer_phone'] }}">{{ $job['buyer_phone'] }}</a><br>{{ $job['dropoff_address'] }} ({{ $job['dropoff_district'] }})
                @if ($job['dropoff_latitude']) &middot; <a class="text-brand-700 underline" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $job['dropoff_latitude'] }},{{ $job['dropoff_longitude'] }}">map</a> @endif</p>
            <p class="text-sm text-stone-600">{{ $job['items'] }}</p>
        @else
            <p><strong>Deliver to:</strong> {{ $job['dropoff_district'] }} (the address shows after you take the job)</p>
        @endif

        @if ($job['status'] === 'open')
            <form method="POST" action="{{ route('rider.jobs.act', [$model, 'accept']) }}">@csrf<button class="btn btn-primary w-full">Take this job</button></form>
            @if ($job['same_route_open'] > 1)
                <form method="POST" action="{{ route('rider.jobs.act', [$model, 'accept-route']) }}">@csrf
                    <button class="btn btn-outline w-full">Take all {{ $job['same_route_open'] }} parcels on this route (one trip)</button>
                </form>
            @endif
        @elseif ($job['status'] === 'assigned')
            <form method="POST" action="{{ route('rider.jobs.act', [$model, 'picked-up']) }}">@csrf<button class="btn btn-primary w-full">{{ $job['kind'] === 'door' ? 'I collected it at the shop' : 'I collected it at the station' }}</button></form>
            <form method="POST" action="{{ route('rider.jobs.act', [$model, 'release']) }}" onsubmit="return confirm('Give this job back?')">@csrf<button class="btn btn-outline w-full">Give the job back</button></form>
        @elseif ($job['status'] === 'picked_up' && $job['dropoff_station'])
            <p class="rounded-lg bg-brand-50 p-2 font-semibold text-brand-900">Take it to {{ $job['dropoff_station']['name'] }}. The station staff mark it received.</p>
        @elseif ($job['status'] === 'picked_up')
            <form method="POST" action="{{ route('rider.jobs.act', [$model, 'deliver']) }}" class="space-y-2">
                @csrf
                <label class="label" for="code">The buyer's 4-digit code (from their SMS)</label>
                <input id="code" name="code" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" required class="input text-center text-2xl tracking-widest">
                <button class="btn btn-primary w-full">Delivered</button>
                <p class="text-xs text-stone-500">{{ $job['code_attempts_left'] }} tries left.</p>
            </form>
            <details class="rounded-xl border border-stone-200 p-3">
                <summary class="cursor-pointer text-sm font-semibold">The buyer cannot give the code? Take a photo instead</summary>
                <form method="POST" action="{{ route('rider.jobs.act', [$model, 'deliver']) }}" enctype="multipart/form-data" class="mt-2 space-y-2">
                    @csrf
                    <input name="photo" type="file" accept="image/*" capture="environment" required class="input">
                    <input name="note" maxlength="300" class="input" placeholder="Who received it? (e.g. his brother Musa)">
                    <button class="btn btn-outline w-full">Delivered (photo)</button>
                </form>
            </details>
        @elseif ($job['status'] === 'delivered')
            <p class="rounded-lg bg-brand-50 p-2 font-semibold text-brand-900">Delivered ✓ Thank you.</p>
        @endif

        @if ($job['status'] === 'picked_up')
            {{-- Live tracking: only while the parcel is with the rider; it stops when it is delivered. --}}
            <p class="rounded-lg bg-amber-50 p-2 text-sm text-amber-900" data-share-status>📍 Keep this page open: your location is shared with the buyer while you carry this parcel. It stops when you deliver.</p>
            <script>
                (() => {
                    const status = document.querySelector('[data-share-status]');
                    if (!('geolocation' in navigator)) { status.textContent = '📍 This phone cannot share its location. The buyer sees the steps only.'; return; }
                    const token = document.querySelector('meta[name=csrf-token]').content;
                    let last = 0;
                    navigator.geolocation.watchPosition((p) => {
                        if (Date.now() - last < 20000) return; // every 20 seconds at most
                        last = Date.now();
                        fetch(@json(route('rider.jobs.location', $model)), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                            body: JSON.stringify({ lat: p.coords.latitude, lng: p.coords.longitude }),
                        }).then((r) => { if (r.ok) status.textContent = '📍 Sharing your location with the buyer (updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + '). It stops when you deliver.'; });
                    }, () => { status.textContent = '📍 Allow location for this page so the buyer can see you are on the way.'; }, { enableHighAccuracy: true, maximumAge: 15000 });
                })();
            </script>
        @endif
    </div>
@endsection
