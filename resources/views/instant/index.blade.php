@extends('layouts.app')

@section('title', '⚡ Instant food delivery')

@section('content')
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold">⚡ Instant food delivery</h1>
            <p class="text-stone-600">Hot food from shops near you, brought by a WiBuy rider in about 30–60 minutes. Order for now, or for a time (lunch at 1:00 pm). Pay by mobile money: the shop cooks once you have paid.</p>
        </div>
        <button type="button" class="btn btn-primary" data-locate="{{ route('instant.index') }}">📍 {{ $point ? 'Update my location' : 'Use my location' }}</button>
    </div>

    @if ($error) <p class="card mb-4 p-4 text-red-700">{{ $error }}</p> @endif
    @unless ($point)
        <p class="card mb-4 bg-amber-50 p-4 text-amber-900">Share your location to see which food shops deliver to you and how long it takes. Below are all food shops.</p>
    @endunless

    @if ($topRated->isNotEmpty())
        <section class="mb-5">
            <h2 class="mb-2 text-lg font-extrabold">⭐ Top rated {{ $point ? 'near you' : 'food' }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                @foreach ($topRated as $product) <x-product-card :product="$product" /> @endforeach
            </div>
        </section>
    @endif

    @forelse ($shops as $row)
        @php($seller = $row['seller'])
        <section class="card mb-5 p-4">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="text-lg font-extrabold"><a href="{{ route('shops.show', $seller) }}" class="hover:underline">{{ $seller->shop_name }}</a></h2>
                    <p class="text-sm text-stone-600">
                        @if ($row['is_open']) <span class="badge bg-brand-100 text-brand-800">Open</span> @else <span class="badge bg-stone-200 text-stone-700">Closed now</span> @endif
                        @if ($seller->hoursLabel()) &middot; {{ $seller->hoursLabel() }} @endif
                        @if ($seller->rating_count > 0) &middot; ★ {{ number_format((float) $seller->rating_avg, 1) }} ({{ $seller->rating_count }}) @endif
                        @if ($row['eta_minutes']) &middot; ⏱ about {{ $row['eta_minutes'] }} min &middot; {{ $row['distance_km'] }} km @endif
                    </p>
                    @unless ($row['is_open']) <p class="text-sm text-stone-500">You can still order for a time when it is open (choose the time at checkout).</p> @endunless
                </div>
                @auth
                    @if ($point)
                        <details class="w-full sm:w-auto">
                            <summary class="btn btn-outline btn-sm cursor-pointer">👥 Start an office order</summary>
                            <form method="POST" action="{{ route('team.store') }}" class="mt-2 grid gap-2 rounded-xl bg-stone-50 p-3 sm:w-96">
                                @csrf
                                <input type="hidden" name="shop" value="{{ $seller->slug }}">
                                <input type="hidden" name="latitude" value="{{ $point['lat'] }}"><input type="hidden" name="longitude" value="{{ $point['lng'] }}">
                                <p class="text-sm text-stone-600">Colleagues add their own meals and pay for them; you pay the delivery once. It all comes together.</p>
                                <input name="title" required maxlength="80" class="input" value="{{ old('title', 'Lunch at the office') }}" aria-label="Name of the order">
                                <div class="grid grid-cols-2 gap-2">
                                    <input name="building" maxlength="80" class="input" placeholder="Building" value="{{ old('building') }}">
                                    <input name="floor" maxlength="20" class="input" placeholder="Floor" value="{{ old('floor') }}">
                                </div>
                                <input name="address" required maxlength="200" class="input" placeholder="Street and area" value="{{ old('address', auth()->user()->area) }}">
                                <select name="district" class="input" aria-label="District">@foreach (config('wibuy.districts') as $d) <option value="{{ $d }}" @selected(old('district', auth()->user()->district) === $d)>{{ $d }}</option> @endforeach</select>
                                <div class="grid grid-cols-2 gap-2">
                                    <input name="name" required maxlength="80" class="input" value="{{ old('name', auth()->user()->name) }}" aria-label="Who receives it">
                                    <input name="phone" required inputmode="tel" class="input" value="{{ old('phone', phone_display(auth()->user()->phone)) }}" aria-label="Phone for the rider">
                                </div>
                                <label class="text-sm">Colleagues can join for
                                    <select name="closes_in" class="input">@foreach ($closeOptions as $m) <option value="{{ $m }}" @selected($m === 30)>{{ $m }} minutes</option> @endforeach</select></label>
                                <label class="text-sm">Deliver at (optional, e.g. lunch time)
                                    <input type="datetime-local" name="deliver_at" class="input" value="{{ old('deliver_at') }}"></label>
                                <button class="btn btn-primary btn-sm">Open the office order</button>
                            </form>
                        </details>
                    @endif
                @endauth
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($products->get($seller->id, collect()) as $product) <x-product-card :product="$product" /> @endforeach
            </div>
        </section>
    @empty
        <p class="card p-4">{{ $point ? 'No food shop delivers to this spot yet.' : 'No food shops yet.' }}</p>
    @endforelse

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
