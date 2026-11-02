@extends('layouts.app')

@section('title', '🔴 Live selling')

@section('content')
    <h1 class="mb-1 text-2xl font-extrabold">🔴 Live selling</h1>
    <p class="mb-5 text-stone-600">Shops show their goods live. Ask questions in the comments and buy while you watch.</p>

    @foreach (['Live now' => $live, 'Coming up' => $upcoming, 'Replays' => $replays] as $heading => $shows)
        @if ($shows->isNotEmpty())
            <h2 class="mb-2 text-lg font-extrabold">{{ $heading }}</h2>
            <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($shows as $show)
                    <a href="{{ route('live.show', $show) }}" class="card block p-4 hover:ring-2 hover:ring-brand-300">
                        <p class="text-sm font-semibold {{ $show->isLive() ? 'text-red-600' : 'text-stone-500' }}">
                            {{ $show->isLive() ? '🔴 LIVE' : ($show->status === 'scheduled' ? ($show->starts_at ? $show->starts_at->format('D j M, H:i') : 'Soon') : 'Replay') }}
                        </p>
                        <p class="font-bold">{{ $show->title }}</p>
                        <p class="text-sm text-stone-600">{{ $show->seller->shop_name }} &middot; {{ $show->products_count }} product(s)</p>
                    </a>
                @endforeach
            </div>
        @endif
    @endforeach

    @if ($live->isEmpty() && $upcoming->isEmpty() && $replays->isEmpty())
        <p class="card p-4">No live shows yet. Shops can start one from their seller dashboard (🔴 Live).</p>
    @endif
@endsection
