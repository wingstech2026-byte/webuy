@extends('layouts.app')

@section('title', 'Seller dashboard')

@section('content')
    @php
        $tabs = [
            'seller.dashboard' => ['Overview', 'seller.dashboard'],
            'seller.orders.*' => ['Orders', 'seller.orders.index'],
            'seller.products.*' => ['Products', 'seller.products.index'],
            'seller.reports' => ['Reports', 'seller.reports'],
            'seller.earnings' => ['Earnings', 'seller.earnings'],
            'seller.reviews' => ['Ratings', 'seller.reviews'],
            'seller.questions.*' => ['Questions', 'seller.questions.index'],
            'seller.promotions*' => ['Promotions', 'seller.promotions'],
            'seller.rfqs.*' => ['Quote requests', 'seller.rfqs.index'],
            'seller.group-buys.*' => ['Group buys', 'seller.group-buys.index'],
            'seller.locations*' => ['Locations', 'seller.locations'],
            'seller.shop*' => ['Shop', 'seller.shop'],
        ];
        $shop = auth()->user()->seller;
        // Helpers: no money, no shop settings.
        if (! auth()->user()->isShopOwner()) {
            unset($tabs['seller.earnings'], $tabs['seller.shop*']);
        }
    @endphp

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <p class="font-extrabold">{{ $shop->shop_name }} @if (auth()->user()->isShopStaff()) <span class="badge bg-stone-100 text-stone-700">Helper</span> @endif @if ($shop->is_verified) <span class="badge bg-brand-100 text-brand-800">✓ Verified</span> @endif</p>
        <a href="{{ route('shops.show', $shop) }}" class="text-sm font-semibold text-brand-700">See my shop page &rarr;</a>
    </div>

    <nav class="-mx-4 mb-6 flex gap-1 overflow-x-auto px-4 pb-1" aria-label="Seller">
        @foreach ($tabs as $pattern => [$label, $route])
            <a href="{{ route($route) }}"
               class="whitespace-nowrap rounded-full px-4 py-2 text-sm font-bold {{ request()->routeIs($pattern) ? 'bg-brand-700 text-white' : 'bg-white text-stone-700 ring-1 ring-stone-200 hover:bg-brand-50' }}">{{ $label }}</a>
        @endforeach
    </nav>

    @yield('seller')
@endsection
