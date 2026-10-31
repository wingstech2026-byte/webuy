@extends('layouts.app')

@section('title', 'Payment')

@php
    use App\Enums\CheckoutStatus;
    use App\Enums\PaymentStatus;

    $waiting = $checkout->status === CheckoutStatus::PendingPayment && $deposit?->status !== PaymentStatus::Failed;
@endphp

@push('head')
    {{-- No JavaScript needed: reload every 5 seconds until the payment is paid or failed. --}}
    @if ($waiting)
        <meta http-equiv="refresh" content="5">
    @endif
@endpush

@section('content')
    <div class="mx-auto max-w-xl space-y-5">
        @if ($waiting)
            <div class="card border-amber-300 bg-amber-50 p-5 text-center">
                <p class="text-5xl" aria-hidden="true">📱</p>
                <h1 class="mt-2 text-2xl font-extrabold text-amber-900">Approve the payment prompt on your phone</h1>
                <p class="mt-2 text-amber-900">
                    We sent a request for <strong>{{ money($checkout->total) }}</strong> to
                    <strong>{{ phone_display($deposit?->phone) }}</strong> on {{ $deposit?->operator->label() }}. Enter your PIN to pay.
                </p>
                <p class="mt-3 text-sm text-amber-800">This page updates by itself. Please keep it open.</p>
                @if ($checkout->expires_at)
                    <p class="mt-1 text-sm font-semibold text-amber-800">Your items are held until {{ $checkout->expires_at->format('g:ia') }}.</p>
                @endif
            </div>

            @if ($fakeGateway && $deposit)
                <div class="card space-y-2 border-dashed border-stone-400 p-4">
                    <p class="text-sm font-bold text-stone-600">Development only: pretend to be the phone</p>
                    <form method="POST" action="{{ route('fake.approve', $deposit) }}">@csrf<button class="btn btn-primary">Simulate approve</button></form>
                    <form method="POST" action="{{ route('fake.decline', $deposit) }}">@csrf<button class="btn btn-danger">Simulate decline</button></form>
                </div>
            @endif

            <form method="POST" action="{{ route('checkout.cancel', $checkout) }}">
                @csrf
                <button class="btn btn-danger">Cancel and start again</button>
            </form>
        @elseif ($checkout->status === CheckoutStatus::Paid)
            <div class="card border-brand-300 bg-brand-50 p-5 text-center">
                <p class="text-5xl" aria-hidden="true">✅</p>
                <h1 class="mt-2 text-2xl font-extrabold text-brand-900">Payment received. Thank you!</h1>
                <p class="mt-2 text-brand-900">The {{ \Illuminate\Support\Str::plural('seller', $checkout->orders->count()) }} will confirm your {{ \Illuminate\Support\Str::plural('order', $checkout->orders->count()) }} soon. We will text you.</p>
            </div>
        @else
            <div class="card border-red-200 bg-red-50 p-5 text-center">
                <h1 class="text-2xl font-extrabold text-red-800">
                    {{ $deposit?->status === PaymentStatus::Failed ? 'The payment did not go through' : 'This checkout was cancelled' }}
                </h1>
                <p class="mt-2 text-red-800">No money was taken. You can try again.</p>
                @if ($deposit?->status === PaymentStatus::Failed && ($deposit->raw_response['reason'] ?? null))
                    <p class="mt-1 text-sm text-red-700">{{ $deposit->raw_response['reason'] }}</p>
                @endif
            </div>
            <a href="{{ route('cart.index') }}" class="btn btn-primary">Back to my cart</a>
        @endif

        <div class="space-y-3">
            @foreach ($checkout->orders as $order)
                <a href="{{ $checkout->status === CheckoutStatus::Paid ? route('orders.show', $order) : '#' }}" class="card block p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-bold">{{ $order->seller->shop_name }}</p>
                            <p class="text-sm text-stone-600">{{ $order->items->sum('quantity') }} item(s) &middot; {{ $order->isPickup() ? 'Pickup' : 'Delivery' }} &middot; {{ money($order->total) }}</p>
                            <p class="text-xs text-stone-500">Order {{ $order->number }}</p>
                        </div>
                        <span class="badge {{ $order->status->badge() }}">{{ $order->statusLabel() }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endsection
