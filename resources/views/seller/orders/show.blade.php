@extends('seller.layout')

@php use App\Enums\OrderStatus; @endphp

@section('seller')
    <a href="{{ route('seller.orders.index') }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; Orders</a>

    <div class="mx-auto max-w-2xl space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <h1 class="text-2xl font-extrabold">Order {{ $order->number }}</h1>
    <a href="{{ route('seller.orders.label', $order) }}" target="_blank" class="mb-3 inline-block text-sm font-semibold text-brand-700 underline">🖨 Print the parcel label</a>
    @if ($order->paid_at) <a href="{{ route('orders.receipt', $order) }}" target="_blank" class="mb-3 ml-3 inline-block text-sm font-semibold text-brand-700 underline">🧾 Receipt</a> @endif
            <span class="badge {{ $order->status->badge() }} text-sm">{{ $order->statusLabel() }}</span>
        </div>

        @if ($order->is_instant)
            <p class="rounded-lg bg-amber-50 p-3 font-semibold text-amber-900">⚡ Instant food order{{ $order->deliver_at ? ': deliver at '.$order->deliver_at->format('D j M, H:i') : ': cook it now' }}@if ($order->teamOrder) &middot; 👥 office order "{{ $order->teamOrder->title }}" (all its orders go in one trip) @endif</p>
        @endif

        {{-- What to do next --}}
        @if ($order->status === OrderStatus::Accepted && $order->is_instant && $order->food_ready_at === null)
            <form method="POST" action="{{ route('seller.orders.food-ready', $order) }}" class="card space-y-2 border-brand-300 p-4">
                @csrf
                <p class="font-bold">Cooked and packed?</p>
                <button class="btn btn-primary">🍲 Food ready: call a WiBuy rider</button>
            </form>
        @elseif ($order->status === OrderStatus::Paid)
            <div class="card space-y-3 border-amber-300 bg-amber-50 p-4">
                <p class="font-bold text-amber-900">The buyer paid. Please accept by {{ $order->accept_by?->format('j M, g:ia') }}, or they are refunded automatically.</p>
                <form method="POST" action="{{ route('seller.orders.accept', $order) }}">@csrf<button class="btn btn-primary">Accept order</button></form>
                <details>
                    <summary class="cursor-pointer text-sm font-semibold text-red-700">I cannot fulfil this order</summary>
                    <form method="POST" action="{{ route('seller.orders.reject', $order) }}" class="mt-2 space-y-2">
                        @csrf
                        <label for="reason" class="label">Tell the buyer why</label>
                        <input id="reason" name="reason" required maxlength="200" class="input" placeholder="e.g. Sold out at the market">
                        @error('reason') <p class="field-error">{{ $message }}</p> @enderror
                        <button class="btn btn-danger">Reject and refund the buyer</button>
                    </form>
                </details>
            </div>
        @elseif ($order->status === OrderStatus::Accepted && $order->deliveryJob?->isActive())
            <div class="card space-y-2 p-4">
                <p class="font-bold">🛵 WiBuy rider: {{ $order->deliveryJob->statusLabel() }}</p>
                @if ($order->deliveryJob->rider) <p class="text-sm">{{ $order->deliveryJob->rider->name }} &middot; {{ $order->deliveryJob->rider->display_phone }}</p> @endif
                <p class="text-sm text-stone-600">The order is marked shipped when the rider collects it.</p>
                <form method="POST" action="{{ route('seller.deliveries.cancel', $order->deliveryJob) }}">@csrf<button class="btn btn-outline btn-sm">Cancel the rider (I will deliver myself)</button></form>
            </div>
        @elseif ($order->status === OrderStatus::Accepted && $order->viaStation())
            {{-- The buyer chose a WiBuy station: the seller only drops the parcel there. --}}
            <div class="card space-y-2 p-4">
                <p class="font-bold">📦 Take this order to the WiBuy station</p>
                @if ($order->dropStation)
                    <p><strong>{{ $order->dropStation->name }}</strong>, {{ $order->dropStation->address }} ({{ $order->dropStation->district }}){{ $order->dropStation->opening_hours ? ' · '.$order->dropStation->opening_hours : '' }}
                        &middot; <a class="text-brand-700 underline" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query={{ $order->dropStation->latitude }},{{ $order->dropStation->longitude }}">map</a></p>
                @endif
                <p class="text-sm text-stone-600">Write <strong class="font-mono">{{ $order->number }}</strong> on the parcel. The station marks it received; from there WiBuy takes it to the buyer.</p>
            </div>
        @elseif ($order->status === OrderStatus::Accepted)
            @unless ($order->isPickup())
                <form method="POST" action="{{ route('seller.orders.rider', $order) }}" class="card space-y-2 p-4">
                    @csrf
                    <p class="font-bold">🛵 Need someone to deliver it?</p>
                    <p class="text-sm text-stone-600">A WiBuy rider collects it from your shop and hands it over with the buyer's code. The rider is paid the delivery fee ({{ money($order->delivery_fee) }}).</p>
                    <button class="btn btn-primary">Request a WiBuy rider</button>
                </form>
            @endunless
            <form method="POST" action="{{ route('seller.orders.ship', $order) }}" class="card space-y-3 p-4">
                @csrf
                <p class="font-bold">{{ $order->isPickup() ? 'When it is packed, tell the buyer it is ready for pickup.' : 'When you send it, mark it as shipped.' }}</p>
                <label for="note" class="label">Delivery note for the buyer (optional)</label>
                <input id="note" name="reason" maxlength="200" class="input" placeholder="{{ $order->isPickup() ? 'e.g. Ask for Mohamed at the shop' : 'e.g. Rider Ibrahim, 076 555 123, arriving tomorrow' }}">
                <button class="btn btn-primary">{{ $order->isPickup() ? 'Ready for pickup' : 'Mark as shipped' }}</button>
            </form>
        @elseif ($order->status === OrderStatus::Shipped)
            <form method="POST" action="{{ route('seller.orders.deliver', $order) }}" class="card space-y-2 p-4">
                @csrf
                <p class="font-bold">Has the buyer got it?</p>
                <p class="text-sm text-stone-600">Mark it delivered. We ask the buyer to confirm; if they do nothing, you are paid automatically after {{ config('wibuy.auto_complete_days') }} days.</p>
                <button class="btn btn-primary">Mark as delivered</button>
            </form>
        @elseif ($order->status === OrderStatus::Returning && $order->returnRequest)
            @include('returns._panel', ['forSeller' => true])
        @elseif ($order->status === OrderStatus::Delivered)
            <p class="card p-4 text-sm">Waiting for the buyer to confirm. It completes automatically {{ config('wibuy.auto_complete_days') }} days after delivery if nobody reports a problem.</p>
        @elseif ($order->status === OrderStatus::Disputed && $order->openDispute)
            <a href="{{ route('disputes.show', $order->openDispute) }}" class="btn btn-danger">The buyer reported a problem &ndash; reply</a>
        @elseif ($order->status === OrderStatus::Completed)
            <p class="card border-brand-200 bg-brand-50 p-4 font-semibold text-brand-900">Completed. {{ money($order->seller_earnings) }} was added to your balance.</p>
        @endif

        <div class="card divide-y divide-stone-100 text-sm">
            @foreach ($order->items as $item)
                <div class="flex justify-between gap-3 p-4">
                    <span>{{ $item->quantity }} &times; {{ $item->display_title }} <span class="text-stone-500">({{ $item->unit }}) @ {{ money($item->unit_price) }}</span></span>
                    <strong>{{ money($item->line_total) }}</strong>
                </div>
            @endforeach
            <div class="flex justify-between p-4"><span>Delivery fee</span><span>{{ money($order->delivery_fee) }}</span></div>
            <div class="flex justify-between p-4"><span>Buyer paid</span><strong>{{ money($order->total) }}</strong></div>
            <div class="flex justify-between p-4"><span>{{ config('wibuy.name') }} commission ({{ rtrim(rtrim($order->commission_percent, '0'), '.') }}% of items)</span><span>- {{ money($order->commission) }}</span></div>
            <div class="flex justify-between p-4 text-base"><strong>You receive</strong><strong class="text-brand-700">{{ money($order->seller_earnings) }}</strong></div>
        </div>

        <div class="card space-y-1 p-4 text-sm">
            <p class="font-bold">{{ $order->isPickup() ? 'Buyer collects from you' : 'Deliver to' }}</p>
            <p>{{ $order->recipient_name }} &middot; <a class="font-semibold text-brand-700" href="tel:+{{ $order->recipient_phone }}">{{ phone_display($order->recipient_phone) }}</a></p>
            @unless ($order->isPickup()) <p>{{ $order->address }}, {{ $order->district }}</p> @endunless
            @if ($order->buyer_note) <p class="text-stone-500">Buyer's note: {{ $order->buyer_note }}</p> @endif
            @if ($order->delivery_note) <p class="text-stone-500">Your note: {{ $order->delivery_note }}</p> @endif
        </div>
    </div>
@endsection
