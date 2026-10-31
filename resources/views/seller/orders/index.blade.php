@extends('seller.layout')

@section('seller')
    <h1 class="mb-4 text-2xl font-extrabold">Orders</h1>

    <div class="-mx-4 mb-4 flex gap-2 overflow-x-auto px-4 pb-1">
        @foreach (['new' => 'New', 'accepted' => 'Accepted', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'problems' => 'Problems', 'finished' => 'Finished'] as $key => $label)
            <a href="{{ route('seller.orders.index', ['tab' => $key]) }}"
               class="badge whitespace-nowrap py-2 text-sm {{ $tab === $key ? 'bg-brand-700 text-white' : 'bg-white ring-1 ring-stone-200' }}">
                {{ $label }} ({{ $tabCounts[$key] }})
            </a>
        @endforeach
    </div>

    @forelse ($orders as $order)
        <a href="{{ route('seller.orders.show', $order) }}" class="card mb-2 block p-4 hover:shadow-md">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-bold">{{ $order->number }} &middot; {{ money($order->total) }}</p>
                    <p class="truncate text-sm text-stone-600">{{ $order->items->map(fn ($i) => $i->quantity.' × '.$i->title)->join(', ') }}</p>
                    <p class="text-xs text-stone-500">{{ $order->isPickup() ? 'Pickup' : 'Delivery to '.$order->district }} &middot; {{ $order->paid_at?->format('j M, g:ia') }}</p>
                    @if ($order->status === \App\Enums\OrderStatus::Paid && $order->accept_by)
                        <p class="text-xs font-semibold text-red-700">Accept by {{ $order->accept_by->format('j M, g:ia') }}</p>
                    @endif
                </div>
                <span class="badge {{ $order->status->badge() }}">{{ $order->statusLabel() }}</span>
            </div>
        </a>
    @empty
        <x-empty title="No orders here">Orders show up here as soon as buyers pay.</x-empty>
    @endforelse

    <div class="mt-4">{{ $orders->links() }}</div>
@endsection
