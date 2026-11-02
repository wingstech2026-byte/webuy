@extends('layouts.app')

@section('title', 'Rider jobs')

@section('content')
    <h1 class="mb-1 text-2xl font-extrabold">🛵 Rider jobs</h1>
    <p class="mb-2 text-stone-600">{{ (clone $done)->count() }} delivered &middot; {{ money((clone $done)->sum('fee') ?: 0) }} in delivery fees. Take a job, collect it at the shop, and hand it over with the buyer's 4-digit code.</p>
    @php($stars = \App\Services\DeliveryService::riderRating(auth()->id()))
    @if ($stars) <p class="mb-2 text-sm">Your rating: <strong>★ {{ $stars['average'] }}</strong> from {{ $stars['count'] }} buyer(s).</p> @endif
    <a href="{{ route('rider.earnings') }}" class="btn btn-outline btn-sm mb-4">💰 My earnings and payouts</a>

    {{-- Instant food: shops near the rider call them. One point when they tap, kept 3 hours at most. --}}
    <div class="card mb-4 flex flex-wrap items-center justify-between gap-2 p-3">
        @if (auth()->user()->isRiderAvailable())
            <p class="text-sm"><strong>🟢 Available for food jobs</strong> until {{ auth()->user()->rider_available_until->format('H:i') }}. Food shops near you call you first.</p>
            <form method="POST" action="{{ route('rider.available') }}">@csrf<button class="btn btn-outline btn-sm">I'm off</button></form>
        @else
            <p class="text-sm">⚡ Want food delivery jobs near you? Say you are available (your location is shared once, for 3 hours).</p>
            <form method="POST" action="{{ route('rider.available') }}" data-available>@csrf<input type="hidden" name="lat"><input type="hidden" name="lng"><button class="btn btn-primary btn-sm">I'm available</button></form>
        @endif
    </div>
    <script>
        document.querySelector('[data-available]')?.addEventListener('submit', (e) => {
            const form = e.target;
            if (form.lat.value) return;
            e.preventDefault();
            navigator.geolocation?.getCurrentPosition((p) => { form.lat.value = p.coords.latitude.toFixed(6); form.lng.value = p.coords.longitude.toFixed(6); form.submit(); },
                () => alert('Allow location in your browser so shops near you can call you.'), { enableHighAccuracy: true, timeout: 15000 });
        });
    </script>

    @if ($mine->isNotEmpty())
        <h2 class="mb-2 text-lg font-extrabold">My deliveries</h2>
        @foreach ($mine as $job)
            <a href="{{ route('rider.jobs.show', $job) }}" class="card mb-2 flex justify-between p-3 text-sm">
                <span><strong>{{ $job->order->number }}</strong> &middot; {{ $job->pickup_name }} &rarr; {{ $job->dropoff_district }}</span>
                <span class="badge bg-brand-50 text-brand-800">{{ $job->statusLabel() }}</span>
            </a>
        @endforeach
    @endif

    <div class="mb-2 mt-6 flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-lg font-extrabold">Open jobs</h2>
        <form method="GET" class="flex gap-2">
            <select name="district" class="input" aria-label="District" onchange="this.form.submit()">
                <option value="">All districts</option>
                @foreach (config('wibuy.districts') as $d) <option value="{{ $d }}" @selected($district === $d)>{{ $d }}</option> @endforeach
            </select>
        </form>
    </div>
    @forelse ($open as $job)
        <div class="card mb-2 flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
            <span>@if ($job->is_instant) <span class="badge bg-amber-100 text-amber-900">⚡ Food</span> @endif <strong>{{ money($job->fee) }}</strong> &middot; from {{ $job->pickup_name }} ({{ $job->pickup_address }}) &rarr; {{ $job->dropoff_district ?? '?' }}</span>
            <span class="flex gap-2">
                <form method="POST" action="{{ route('rider.jobs.act', [$job, 'accept']) }}">@csrf<button class="btn btn-primary btn-sm">Take this job</button></form>
                @if ($job->kind === 'transfer' && $open->where('kind', 'transfer')->where('pickup_station_id', $job->pickup_station_id)->where('dropoff_station_id', $job->dropoff_station_id)->count() > 1)
                    <form method="POST" action="{{ route('rider.jobs.act', [$job, 'accept-route']) }}">@csrf<button class="btn btn-outline btn-sm">Take all on this route</button></form>
                @endif
            </span>
        </div>
    @empty
        <p class="card p-4">No open jobs right now.</p>
    @endforelse
@endsection
