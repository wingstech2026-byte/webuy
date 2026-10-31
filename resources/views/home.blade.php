@extends('layouts.app')

@section('content')
    <section class="mb-6 rounded-2xl bg-brand-700 p-5 text-white">
        <h1 class="text-2xl font-extrabold leading-tight sm:text-3xl">{{ config('wibuy.tagline') }}</h1>
        <p class="mt-1 text-brand-100">
            <strong>Wi</strong> means "we" in Krio. Shops, market traders and wholesalers from across Sierra Leone.
            Pay with Orange Money or Afrimoney &ndash; the seller is paid only after you confirm you got your order.
        </p>
    </section>

    {{-- Categories --}}
    <section class="mb-8" aria-labelledby="cats">
        <h2 id="cats" class="mb-3 text-lg font-extrabold">Shop by category</h2>
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
            <h2 id="deals" class="mb-3 text-lg font-extrabold">🔥 Deals of the day</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($deals as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        </section>
    @endif

    @if ($featured->isNotEmpty())
        <section class="mb-8" aria-labelledby="featured">
            <h2 id="featured" class="mb-3 text-lg font-extrabold">Featured</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($featured as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        </section>
    @endif

    <section class="mb-8" aria-labelledby="new">
        <div class="mb-3 flex items-center justify-between">
            <h2 id="new" class="text-lg font-extrabold">New arrivals</h2>
            <a href="{{ route('search') }}" class="text-sm font-bold text-brand-700">See all &rarr;</a>
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
            <h2 id="sellers" class="mb-3 text-lg font-extrabold">Top sellers</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($topSellers as $seller)
                    <x-seller-card :seller="$seller" />
                @endforeach
            </div>
        </section>
    @endif

    <section class="card p-5 text-center">
        <h2 class="text-lg font-extrabold">Have something to sell?</h2>
        <p class="mt-1 text-stone-600">Open a shop on {{ config('wibuy.name') }} and reach buyers all over Salone. Get paid to your mobile money.</p>
        <a href="{{ route('sell.apply') }}" class="btn btn-primary mx-auto mt-3 max-w-xs">Start selling</a>
    </section>
@endsection
