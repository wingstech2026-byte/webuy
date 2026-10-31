@extends('layouts.app')

@section('title', 'My orders')

@section('content')
    <h1 class="mb-4 text-2xl font-extrabold">My orders</h1>

    @if ($orders->isEmpty())
        <x-empty title="You have no orders yet" action="Start shopping" :href="route('home')">
            When you buy something it shows up here, with its delivery status.
        </x-empty>
    @else
        <div class="space-y-3">
            @foreach ($orders as $order)
                <a href="{{ route('orders.show', $order) }}" class="card block p-4 transition hover:shadow-md">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-bold">{{ $order->seller->shop_name }}</p>
                            <p class="truncate text-sm text-stone-600">{{ $order->items->pluck('title')->join(', ') }}</p>
                            <p class="text-xs text-stone-500">{{ $order->number }} &middot; {{ $order->created_at->format('j M Y') }} &middot; {{ money($order->total) }}</p>
                        </div>
                        <span class="badge {{ $order->status->badge() }}">{{ $order->statusLabel() }}</span>
                    </div>
                    @if (in_array($order->status, [\App\Enums\OrderStatus::Shipped, \App\Enums\OrderStatus::Delivered], true))
                        <p class="mt-2 text-sm font-bold text-brand-700">Got it? Tap to confirm delivery.</p>
                    @endif
                </a>
            @endforeach
        </div>
        <div class="mt-6">{{ $orders->links() }}</div>
    @endif
@endsection
