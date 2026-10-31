@extends('layouts.app')

@section('title', $seller->shop_name)

@section('meta')
    <meta name="description" content="{{ $seller->shop_name }} on {{ config('wibuy.name') }}: {{ \Illuminate\Support\Str::limit($seller->description ?? $seller->location, 150) }}">
    <meta property="og:site_name" content="{{ config('wibuy.name') }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $seller->shop_name }} on {{ config('wibuy.name') }}">
    <meta property="og:description" content="{{ $seller->location }}. {{ config('wibuy.tagline') }}">
    <meta property="og:url" content="{{ route('shops.show', $seller) }}">
    @if ($seller->logo?->isReady()) <meta property="og:image" content="{{ $seller->logo->url }}"> @endif
@endsection

@section('content')
    <div class="card mb-6 flex flex-wrap items-center gap-4 p-5">
        <x-shop-logo :seller="$seller" class="size-20 shrink-0 rounded-full text-3xl" />
        <div class="min-w-0 flex-1">
            <h1 class="flex flex-wrap items-center gap-2 text-2xl font-extrabold">
                {{ $seller->shop_name }}
                @if ($seller->is_verified) <span class="badge bg-brand-100 text-brand-800">✓ Verified seller</span> @endif
            </h1>
            <p class="text-stone-600">{{ $seller->location }}</p>
            <x-stars :rating="$seller->rating_avg" :count="$seller->rating_count" />
            <p class="mt-1 text-sm font-semibold" title="On-time delivery, cancellations and problem reports in the last 90 days">
                Delivery reliability: {{ $seller->reliability_score === null ? 'New shop' : $seller->reliability_score.'/100' }}
            </p>
            @if ($seller->description) <p class="mt-2 text-stone-700">{{ $seller->description }}</p> @endif
            <p class="mt-2 text-sm text-stone-500">
                {{ $seller->offers_delivery ? 'Delivers' : '' }}{{ $seller->offers_delivery && $seller->offers_pickup ? ' · ' : '' }}{{ $seller->offers_pickup ? 'Pickup at '.$seller->pickup_address : '' }}
            </p>
        </div>
    </div>

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search this shop" class="input max-w-xs">
        <select name="sort" class="input max-w-xs" aria-label="Sort">
            @foreach ($sorts as $value => $label)
                <option value="{{ $value }}" @selected($sort === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="btn btn-outline btn-sm">Go</button>
    </form>

    @if ($products->isEmpty())
        <x-empty title="No products here yet">This shop has not listed anything yet.</x-empty>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($products as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
        <div class="mt-6">{{ $products->links() }}</div>
    @endif

    <section class="mt-8">
        <h2 class="mb-3 text-xl font-extrabold">What buyers say</h2>
        @forelse ($reviews as $review)
            <div class="card mb-2 p-4">
                <div class="flex items-center justify-between gap-2">
                    <strong>{{ $review->buyer->first_name }}</strong>
                    <x-stars :rating="$review->rating" />
                </div>
                @if ($review->comment) <p class="mt-1 text-stone-700">{{ $review->comment }}</p> @endif
            </div>
        @empty
            <p class="text-stone-500">No ratings yet.</p>
        @endforelse
    </section>
@endsection
