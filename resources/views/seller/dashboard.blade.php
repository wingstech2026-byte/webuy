@extends('seller.layout')

@section('seller')
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        @foreach ([
            ['New (accept them!)', $counts['new'], route('seller.orders.index', ['tab' => 'new'])],
            ['Accepted', $counts['accepted'], route('seller.orders.index', ['tab' => 'accepted'])],
            ['Shipped', $counts['shipped'], route('seller.orders.index', ['tab' => 'shipped'])],
            ['Delivered', $counts['delivered'], route('seller.orders.index', ['tab' => 'delivered'])],
            ['Problems', $counts['disputed'], route('seller.orders.index', ['tab' => 'problems'])],
        ] as [$label, $value, $href])
            <a href="{{ $href }}" class="card block p-4 hover:shadow-md">
                <p class="text-sm font-semibold text-stone-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-extrabold text-brand-800">{{ $value }}</p>
            </a>
        @endforeach
    </div>

    <div class="mt-3 grid gap-3 sm:grid-cols-3">
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Pending (orders in progress)</p><p class="mt-1 text-2xl font-extrabold">{{ money($money['pending']) }}</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Available to pay out</p><p class="mt-1 text-2xl font-extrabold text-brand-700">{{ money($money['available']) }}</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Paid out</p><p class="mt-1 text-2xl font-extrabold">{{ money($money['paid_out']) }}</p></div>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <a href="{{ route('seller.products.create') }}" class="btn btn-primary">Add a product</a>
        <a href="{{ route('seller.earnings') }}" class="btn btn-outline">Request a payout</a>
    </div>

    @if ($productCount === 0)
        <div class="card mt-6 border-amber-300 bg-amber-50 p-4 text-amber-900">
            <p class="font-bold">Your shop is approved! Add your first product so buyers can find you.</p>
            <p class="text-sm">Tip: set your delivery fees per district on the <a class="underline" href="{{ route('seller.shop') }}">Shop</a> page.</p>
        </div>
    @endif

    <h2 class="mb-3 mt-8 text-lg font-extrabold">New orders to accept</h2>
    @forelse ($newOrders as $order)
        <a href="{{ route('seller.orders.show', $order) }}" class="card mb-2 flex items-center justify-between gap-3 p-4 hover:shadow-md">
            <span>
                <strong>{{ $order->number }}</strong> &middot; {{ $order->items->sum('quantity') }} item(s) &middot; {{ money($order->total) }}
                <span class="block text-xs text-red-700">Accept by {{ $order->accept_by?->format('j M, g:ia') }} or the buyer is refunded</span>
            </span>
            <span class="badge bg-amber-100 text-amber-800">New</span>
        </a>
    @empty
        <p class="text-stone-500">No new orders right now.</p>
    @endforelse

    @if ($openDisputes->isNotEmpty())
        <h2 class="mb-3 mt-8 text-lg font-extrabold">Problems reported by buyers</h2>
        @foreach ($openDisputes as $dispute)
            <a href="{{ route('disputes.show', $dispute) }}" class="card mb-2 block p-4 hover:shadow-md">
                <strong>{{ $dispute->order->number }}</strong> &middot; {{ $dispute->reason }}
                <span class="block text-xs text-stone-500">{{ $dispute->seller_response ? 'You replied' : 'Please reply' }}</span>
            </a>
        @endforeach
    @endif

    @if ($lowStock->isNotEmpty())
        <h2 class="mb-3 mt-8 text-lg font-extrabold">Running low</h2>
        @foreach ($lowStock as $product)
            <a href="{{ route('seller.products.edit', $product) }}" class="card mb-2 flex justify-between p-4 hover:shadow-md">
                <span>{{ $product->title }}</span><strong class="{{ $product->stock === 0 ? 'text-red-700' : '' }}">{{ $product->stock }} left</strong>
            </a>
        @endforeach
    @endif
@endsection
