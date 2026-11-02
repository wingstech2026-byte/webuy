@extends('layouts.app')

@section('content')
    <section class="mb-6 rounded-2xl bg-brand-700 p-5 text-white">
        <h1 class="text-2xl font-extrabold leading-tight sm:text-3xl">{{ __(config('wibuy.tagline')) }}</h1>
        <p class="mt-1 text-brand-100">
            <strong>Wi</strong> means "we" in Krio. Shops, market traders and wholesalers from across Sierra Leone.
            Pay with Orange Money or Afrimoney &ndash; the seller is paid only after you confirm you got your order.
        </p>
    </section>

    {{-- Categories --}}
    <section class="mb-8" aria-labelledby="cats">
        <h2 id="cats" class="mb-3 text-lg font-extrabold">{{ __('Shop by category') }}</h2>
        @if ($categories->isEmpty())
            <p class="text-stone-500">No categories yet.</p>
        @else
            <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-2 sm:grid sm:grid-cols-5 sm:overflow-visible">
                @foreach ($categories as $category)
                    <a href="{{ route('categories.show', $category) }}"
                       class="flex min-w-28 shrink-0 flex-col items-center gap-1 rounded-2xl bg-white p-3 text-center text-sm font-semibold ring-1 ring-stone-200 hover:bg-brand-50">
                        <span class="text-3xl" aria-hidden="true">{{ $category->emoji }}</span>
                        <span class="leading-tight">{{ $category->name }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    @if ($deals->isNotEmpty())
        <section class="mb-8" aria-labelledby="deals">
            <h2 id="deals" class="mb-3 text-lg font-extrabold">{{ __('🔥 Deals of the day') }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($deals as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        </section>
    @endif

    <a href="{{ route('group-buys.index') }}" class="card mb-6 flex items-center justify-between gap-3 bg-brand-700 p-4 text-white">
        <span><strong class="text-lg">👥 Buy together, pay less</strong><br><span class="text-sm text-white/80">Group prices when enough people buy. Money back if the group does not fill.</span></span>
        <span aria-hidden="true">&rarr;</span>
    </a>

    @if ($recent->isNotEmpty())
        <section class="mb-8" aria-labelledby="recent">
            <div class="mb-3 flex items-center justify-between">
                <h2 id="recent" class="text-lg font-extrabold">{{ __('Recently viewed') }}</h2>
                <a href="{{ route('compare.index') }}" class="text-sm font-semibold text-brand-700">Compare &rarr;</a>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($recent->take(4) as $product) <x-product-card :product="$product" /> @endforeach
            </div>
        </section>
    @endif

    @if ($sponsored->isNotEmpty())
        <section class="mb-8" aria-labelledby="sponsored">
            <h2 id="sponsored" class="mb-3 text-lg font-extrabold">📣 {{ __('Sponsored') }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($sponsored as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        </section>
    @endif
    @if ($forYou->isNotEmpty())
        <section class="mb-8" aria-labelledby="for-you">
            <h2 id="for-you" class="mb-3 text-lg font-extrabold">{{ __('✨ For you') }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($forYou as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        </section>
    @endif
    @if ($featured->isNotEmpty())
        <section class="mb-8" aria-labelledby="featured">
            <h2 id="featured" class="mb-3 text-lg font-extrabold">{{ __('Featured') }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($featured as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        </section>
    @endif

    <section class="mb-8" aria-labelledby="new">
        <div class="mb-3 flex items-center justify-between">
            <h2 id="new" class="text-lg font-extrabold">{{ __('New arrivals') }}</h2>
            <a href="{{ route('search') }}" class="text-sm font-bold text-brand-700">{{ __('See all') }} &rarr;</a>
        </div>
        @if ($newArrivals->isEmpty())
            <x-empty title="No products yet">Sellers are setting up their shops. Come back soon!</x-empty>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($newArrivals as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        @endif
    </section>

    @if ($topSellers->isNotEmpty())
        <section class="mb-8" aria-labelledby="sellers">
            <h2 id="sellers" class="mb-3 text-lg font-extrabold">{{ __('Top sellers') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($topSellers as $seller)
                    <x-seller-card :seller="$seller" />
                @endforeach
            </div>
        </section>
    @endif

    <section class="card p-5 text-center">
        <h2 class="text-lg font-extrabold">{{ __('Have something to sell?') }}</h2>
        <p class="mt-1 text-stone-600">Open a shop on {{ config('wibuy.name') }} and reach buyers all over Salone. Get paid to your mobile money.</p>
        <a href="{{ route('sell.apply') }}" class="btn btn-primary mx-auto mt-3 max-w-xs">Start selling</a>
    </section>
@endsection
