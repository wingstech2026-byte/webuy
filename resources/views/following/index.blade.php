@extends('layouts.app')

@section('title', 'Following')

@section('content')
    <h1 class="mb-1 text-2xl font-extrabold">❤ Following</h1>
    <p class="mb-5 text-stone-600">News from the shops you follow: new products, offers and live shows.</p>

    @if ($shops->isEmpty())
        <p class="card p-4">You do not follow any shop yet. Tap "Follow" on a shop page to see its news here.</p>
    @else
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach ($shops as $shop) <a href="{{ route('shops.show', $shop) }}" class="badge bg-white px-3 py-2 text-sm ring-1 ring-stone-200">{{ $shop->shop_name }}</a> @endforeach
        </div>

        @if ($live->isNotEmpty())
            <h2 class="mb-2 text-lg font-extrabold">🔴 Live shows</h2>
            <div class="mb-6 grid gap-2 sm:grid-cols-2">
                @foreach ($live as $show)
                    <a href="{{ route('live.show', $show) }}" class="card p-3"><span class="{{ $show->isLive() ? 'font-bold text-red-600' : 'text-stone-500' }}">{{ $show->isLive() ? 'LIVE' : 'Coming up' }}</span> &middot; {{ $show->title }} &middot; {{ $show->seller->shop_name }}</a>
                @endforeach
            </div>
        @endif

        @if ($offers->isNotEmpty())
            <h2 class="mb-2 text-lg font-extrabold">🏷 Offers</h2>
            <div class="mb-6 grid gap-2 sm:grid-cols-2">
                @foreach ($offers as $offer)
                    <a href="{{ route('shops.show', $offer->seller) }}" class="card p-3"><strong>{{ $offer->seller->shop_name }}</strong>: {{ $offer->label }}</a>
                @endforeach
            </div>
        @endif

        <h2 class="mb-2 text-lg font-extrabold">🆕 New products</h2>
        @if ($products->isEmpty())
            <p class="card p-4 text-sm text-stone-500">No new products in the last 30 days.</p>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($products as $product) <x-product-card :product="$product" /> @endforeach
            </div>
        @endif
    @endif
@endsection
