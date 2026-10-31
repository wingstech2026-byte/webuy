@extends('layouts.app')

@section('title', 'Order '.$order->number)

@php
    use App\Enums\OrderStatus;

    // The normal path of an order, with the time each step happened.
    $steps = [
        ['Ordered and paid', $order->paid_at],
        ['Seller accepted', $order->accepted_at],
        [$order->shipped_label, $order->shipped_at],
        ['Delivered', $order->delivered_at],
        ['Completed', $order->completed_at],
    ];
    $sideNote = match ($order->status) {
        OrderStatus::Rejected => ['The seller could not fulfil this order.', $order->rejection_reason],
        OrderStatus::Cancelled => ['This order was cancelled.', $order->refund_reason],
        OrderStatus::Refunded => ['Refunded: '.money($order->amount_paid).' was sent back to your mobile money.', $order->refund_reason],
        OrderStatus::Disputed => ['You reported a problem. Your money is on hold until it is decided.', null],
        default => null,
    };
@endphp

@section('content')
    <a href="{{ route('orders.index') }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; My orders</a>

    <div class="mx-auto max-w-2xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-2xl font-extrabold">Order {{ $order->number }}</h1>
                <p class="text-stone-600">from <a class="font-semibold text-brand-700" href="{{ route('shops.show', $order->seller) }}">{{ $order->seller->shop_name }}</a></p>
            </div>
            <span class="badge {{ $order->status->badge() }} text-sm">{{ $order->statusLabel() }}</span>
        </div>

        @if ($sideNote)
            <div class="card border-amber-300 bg-amber-50 p-4 text-amber-900">
                <p class="font-bold">{{ $sideNote[0] }}</p>
                @if ($sideNote[1]) <p class="text-sm">{{ $sideNote[1] }}</p> @endif
                @if (in_array($order->status, [OrderStatus::Rejected, OrderStatus::Cancelled], true) && $order->paid_at)
                    <p class="mt-1 text-sm">Your refund of {{ money($order->amount_paid) }} is on the way.</p>
                @endif
            </div>
        @endif

        {{-- Timeline --}}
        <ol class="card space-y-3 p-4" aria-label="Order progress">
            @foreach ($steps as [$label, $at])
                <li class="flex items-center gap-3">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-bold {{ $at ? 'bg-brand-600 text-white' : 'bg-stone-200 text-stone-500' }}" aria-hidden="true">{{ $at ? '✓' : $loop->iteration }}</span>
                    <span class="flex-1 {{ $at ? 'font-semibold' : 'text-stone-500' }}">{{ $label }}</span>
                    <span class="text-xs text-stone-500">{{ $at?->format('j M, g:ia') }}</span>
                </li>
            @endforeach
        </ol>

        @if ($order->delivery_note)
            <div class="card p-4"><p class="text-sm font-bold">Note from the seller</p><p>{{ $order->delivery_note }}</p></div>
        @endif

        {{-- Buyer actions --}}
        @if ($isBuyer)
            @if (in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true))
                <div class="card space-y-3 p-4">
                    <p class="font-bold">Did you get your order?</p>
                    <form method="POST" action="{{ route('orders.confirm', $order) }}" onsubmit="return confirm('Confirm you received everything? The seller will then be paid.')">
                        @csrf
                        <button class="btn btn-primary">Yes, confirm delivery</button>
                    </form>
                    <p class="text-xs text-stone-500">If you do nothing, the order completes by itself {{ config('wibuy.auto_complete_days') }} days after it was {{ $order->delivered_at ? 'delivered' : 'shipped' }}.</p>
                </div>
            @endif

            @if ($order->returnRequest)
                @include('returns._panel', ['forSeller' => false])
            @elseif ($order->status === OrderStatus::Delivered)
                <a href="{{ route('returns.create', $order) }}" class="btn btn-outline">↩️ Return items</a>
            @endif

            @if (in_array($order->status, OrderStatus::disputable(), true))
                <a href="{{ route('disputes.create', $order) }}" class="btn btn-danger">Report a problem</a>
            @endif

            @if ($order->disputes->isNotEmpty())
                <a href="{{ route('disputes.show', $order->disputes->first()) }}" class="btn btn-outline">See the problem report</a>
            @endif

            @if ($canReview)
                <form method="POST" action="{{ route('orders.review', $order) }}" class="card space-y-4 p-4">
                    @csrf
                    <h2 class="text-lg font-extrabold">Leave a review</h2>
                    @error('seller_rating') <p class="field-error">{{ $message }}</p> @enderror

                    @unless ($order->sellerReview)
                        <fieldset>
                            <legend class="label">How was {{ $order->seller->shop_name }}?</legend>
                            <div class="flex flex-wrap gap-2">
                                @for ($i = 5; $i >= 1; $i--)
                                    <label class="choice min-h-12 px-3"><input type="radio" name="seller_rating" value="{{ $i }}" class="size-5 accent-brand-700">{{ str_repeat('★', $i) }}</label>
                                @endfor
                            </div>
                            <textarea name="seller_comment" rows="2" maxlength="1000" class="input mt-2" placeholder="Anything to say about the seller? (optional)"></textarea>
                        </fieldset>
                    @endunless

                    @foreach ($order->items as $item)
                        @continue($item->review)
                        <fieldset>
                            <legend class="label">{{ $item->title }}</legend>
                            <div class="flex flex-wrap gap-2">
                                @for ($i = 5; $i >= 1; $i--)
                                    <label class="choice min-h-12 px-3"><input type="radio" name="items[{{ $item->id }}][rating]" value="{{ $i }}" class="size-5 accent-brand-700">{{ str_repeat('★', $i) }}</label>
                                @endfor
                            </div>
                            <textarea name="items[{{ $item->id }}][comment]" rows="2" maxlength="1000" class="input mt-2" placeholder="What did you think? (optional)"></textarea>
                        </fieldset>
                    @endforeach

                    <button class="btn btn-primary">Send review</button>
                </form>
            @elseif ($order->status === OrderStatus::Completed)
                <p class="text-center text-sm text-stone-500">Thank you for your review.</p>
            @endif
        @endif

        {{-- Items and money --}}
        <div class="card divide-y divide-stone-100 text-sm">
            @foreach ($order->items as $item)
                <div class="flex items-center gap-3 p-4">
                    @if ($item->thumb_url)
                        <img src="{{ $item->thumb_url }}" alt="" loading="lazy" class="size-12 shrink-0 rounded-lg object-cover">
                    @endif
                    <span class="flex-1">{{ $item->quantity }} &times; {{ $item->display_title }} <span class="text-stone-500">@ {{ money($item->unit_price) }}</span></span>
                    <strong>{{ money($item->line_total) }}</strong>
                </div>
            @endforeach
            <div class="flex justify-between p-4"><span>Delivery</span><span>{{ money($order->delivery_fee) }}</span></div>
            @if (! \App\Support\Money::isZero($order->discount))
                <div class="flex justify-between p-4 text-brand-700"><span>Coupon and WiBuy Pass savings</span><span>-{{ money($order->discount) }}</span></div>
            @endif
            <div class="flex justify-between p-4 text-base"><strong>Total paid</strong><strong>{{ money($order->amount_paid) }}</strong></div>
            @if ($order->gift_note)
                <div class="p-4 text-brand-700">🎁 {{ $order->gift_note }}</div>
            @endif
        </div>

        <div class="card space-y-1 p-4 text-sm">
            @if ($order->isPickup())
                <p class="font-bold">Pickup from the seller</p>
                <p>{{ $order->seller->pickup_address }}, {{ $order->seller->location }}</p>
            @else
                <p class="font-bold">Delivery to</p>
                <p>{{ $order->recipient_name }}, {{ phone_display($order->recipient_phone) }}</p>
                <p>{{ $order->address }}, {{ $order->district }}</p>
            @endif
            @if ($order->buyer_note) <p class="text-stone-500">Your note: {{ $order->buyer_note }}</p> @endif
        </div>
    </div>
@endsection
