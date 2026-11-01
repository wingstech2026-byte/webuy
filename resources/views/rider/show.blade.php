@extends('layouts.app')

@section('title', 'Delivery')

@section('content')
    <a href="{{ route('rider.index') }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; Rider jobs</a>

    <div class="card mx-auto max-w-xl space-y-3 p-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-xl font-extrabold">{{ $job['order_number'] ?? 'Delivery job' }}</h1>
            <span class="badge bg-brand-50 text-brand-800">{{ $job['status_label'] }}</span>
        </div>
        <p><strong>Pay:</strong> {{ money($job['fee']) }}</p>
        <p><strong>Collect at:</strong> {{ $job['pickup_name'] }}, {{ $job['pickup_address'] }}
            @if ($job['pickup_latitude']) &middot; <a class="text-brand-700 underline" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $job['pickup_latitude'] }},{{ $job['pickup_longitude'] }}">map</a> @endif</p>

        @if ($job['is_mine'])
            <p><strong>Deliver to:</strong> {{ $job['buyer_name'] }}, <a class="text-brand-700" href="tel:+{{ $job['buyer_phone'] }}">{{ $job['buyer_phone'] }}</a><br>{{ $job['dropoff_address'] }} ({{ $job['dropoff_district'] }})
                @if ($job['dropoff_latitude']) &middot; <a class="text-brand-700 underline" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $job['dropoff_latitude'] }},{{ $job['dropoff_longitude'] }}">map</a> @endif</p>
            <p class="text-sm text-stone-600">{{ $job['items'] }}</p>
        @else
            <p><strong>Deliver to:</strong> {{ $job['dropoff_district'] }} (the address shows after you take the job)</p>
        @endif

        @if ($job['status'] === 'open')
            <form method="POST" action="{{ route('rider.jobs.act', [$model, 'accept']) }}">@csrf<button class="btn btn-primary w-full">Take this job</button></form>
        @elseif ($job['status'] === 'assigned')
            <form method="POST" action="{{ route('rider.jobs.act', [$model, 'picked-up']) }}">@csrf<button class="btn btn-primary w-full">I collected it at the shop</button></form>
            <form method="POST" action="{{ route('rider.jobs.act', [$model, 'release']) }}" onsubmit="return confirm('Give this job back?')">@csrf<button class="btn btn-outline w-full">Give the job back</button></form>
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
    </div>
@endsection
