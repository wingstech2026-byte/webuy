@extends('layouts.app')

@section('title', $team['title'])

@section('content')
    <div class="card mb-4 space-y-2 p-4">
        <p class="text-sm font-semibold text-brand-700">👥 Office order from {{ $team['shop']['name'] }}</p>
        <h1 class="text-2xl font-extrabold">{{ $team['title'] }}</h1>
        <p>Opened by {{ $team['organiser'] }} &middot; to <strong>{{ $team['place'] }}</strong></p>
        <p>
            @if ($team['deliver_at']) 🕐 Delivery at <strong>{{ \Illuminate\Support\Carbon::parse($team['deliver_at'])->format('D j M, H:i') }}</strong> &middot; @else ⚡ Delivered as soon as it is cooked &middot; @endif
            @if ($team['is_open']) open until {{ \Illuminate\Support\Carbon::parse($team['closes_at'])->format('H:i') }} @else <span class="badge bg-stone-200 text-stone-700">Closed</span> @endif
        </p>
        <p class="text-sm text-stone-600">Each person pays for their own food. {{ $team['organiser'] }} pays the delivery once, and everything comes in one trip.</p>
        @if ($team['members']->isNotEmpty())
            <p class="text-sm"><strong>Already in:</strong> {{ $team['members']->map(fn ($m) => $m['name'].' ('.$m['items'].')')->implode(', ') }}</p>
        @endif
        @if ($team['is_open'])
            <div class="flex flex-wrap items-center gap-2">
                <input readonly class="input max-w-md text-sm" value="{{ $team['share_url'] }}" onclick="this.select()" aria-label="Link to share">
                <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="https://wa.me/?text={{ urlencode('Lunch order from '.$team['shop']['name'].': add your meal here '.$team['share_url']) }}">Share on WhatsApp</a>
                @if ($team['is_organiser'])
                    <form method="POST" action="{{ route('team.close', $model) }}">@csrf<button class="btn btn-outline btn-sm">Close it now</button></form>
                @endif
            </div>
            @if ($team['is_organiser'] && ! $team['organiser_has_ordered'])
                <p class="rounded-lg bg-amber-50 p-2 text-sm text-amber-900">Add your own meal too: your order carries the delivery fee for everyone.</p>
            @endif
        @endif
    </div>

    @if ($team['is_open'])
        <h2 class="mb-2 text-lg font-extrabold">Add your meal</h2>
        <p class="mb-3 text-sm text-stone-600">Add dishes to your cart, then check out for this office order.</p>
        @auth
            <a href="{{ route('checkout.show', ['team' => $team['token']]) }}" class="btn btn-primary mb-4">Check out my cart for this office order</a>
        @else
            <a href="{{ route('login') }}" class="btn btn-primary mb-4">Log in to add your meal</a>
        @endauth
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ($products as $product) <x-product-card :product="$product" /> @endforeach
        </div>
    @endif
@endsection
