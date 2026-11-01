@extends('layouts.app')

@section('title', 'Rider jobs')

@section('content')
    <h1 class="mb-1 text-2xl font-extrabold">🛵 Rider jobs</h1>
    <p class="mb-4 text-stone-600">{{ (clone $done)->count() }} delivered &middot; {{ money((clone $done)->sum('fee') ?: 0) }} in delivery fees. Take a job, collect it at the shop, and hand it over with the buyer's 4-digit code.</p>

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
            <span><strong>{{ money($job->fee) }}</strong> &middot; from {{ $job->pickup_name }} ({{ $job->pickup_address }}) &rarr; {{ $job->dropoff_district ?? '?' }}</span>
            <form method="POST" action="{{ route('rider.jobs.act', [$job, 'accept']) }}">@csrf<button class="btn btn-primary btn-sm">Take this job</button></form>
        </div>
    @empty
        <p class="card p-4">No open jobs right now.</p>
    @endforelse
@endsection
