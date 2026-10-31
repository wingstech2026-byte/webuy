@extends('layouts.app')

@section('title', 'Help '.$cut->user->first_name.' cut the price')

@php
    use App\Support\Money;
    $product = $campaign->product;
    $total = Money::sub($cut->start_price, $campaign->floor_price);
    $done = Money::sub($cut->start_price, $cut->current_price);
    $percent = Money::isZero($total) ? 100 : (int) min(100, floor((float) $done * 100 / (float) $total));
    $alreadyCut = auth()->check() && $cut->helpers->contains('helper_id', auth()->id());
    
@endphp

@section('content')
    <div class="mx-auto max-w-md space-y-4">
        <div class="card overflow-hidden">
            <x-product-image :product="$product" class="aspect-square w-full object-cover" />
            <div class="space-y-2 p-4">
                <h1 class="text-xl font-extrabold">✂️ Help {{ $cut->user->first_name }} cut the price</h1>
                <p class="font-semibold">{{ $product->title }}</p>
                <p>
                    <span class="text-2xl font-extrabold text-brand-700">{{ money($cut->current_price) }}</span>
                    <span class="text-stone-500 line-through">{{ money($cut->start_price) }}</span>
                    <span class="text-sm text-stone-600">Lowest: {{ money($campaign->floor_price) }}</span>
                </p>
                <div class="h-3 overflow-hidden rounded-full bg-stone-200" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full bg-brand-600" style="width: {{ $percent }}%"></div>
                </div>
                <p class="text-sm text-stone-600">{{ $percent }}% done &middot; {{ $cut->helpers->count() }} friend(s) helped &middot;
                    @if ($cut->isActive()) ends {{ $cut->expires_at->diffForHumans() }} @else {{ $cut->status === 'reached' ? 'lowest price reached!' : 'ended' }} @endif
                </p>
            </div>
        </div>

        @endauth
        @if ($cut->isActive())
            <p class="card p-4">Share this link with friends on WhatsApp so they can help cut the price for you.</p>
        @endif
        @if ($cut->isActive())
            @auth
                @if (auth()->id() === $cut->user_id)
                    <p class="card p-4">This is your price cut. Share the link with friends on WhatsApp so they can cut for you.</p>
                @elseif ($alreadyCut)
                    <p class="card p-4">You already helped. Thank you!</p>
                @else
                    <form method="POST" action="{{ route('price-cuts.cut', $cut) }}">@csrf
                        <button class="btn btn-primary w-full text-lg">✂️ Cut the price</button>
                    </form>
                @endif
            @else
                {{-- Posting while logged out goes to the login page, which then brings them back here. --}}
                <form method="POST" action="{{ route('price-cuts.cut', $cut) }}">@csrf
                    <button class="btn btn-primary w-full text-lg">Log in to cut the price</button>
                </form>
                <p class="text-center text-sm text-stone-600">New to WiBuy? You get a welcome coupon pack when you join.</p>
            @endauth
        @endif

        @if ($cut->helpers->isNotEmpty())
            <div class="card divide-y divide-stone-100">
                @foreach ($cut->helpers as $helper)
                    <div class="flex justify-between p-3 text-sm"><span>{{ $helper->helper->first_name }}</span><span class="font-bold text-brand-700">-{{ money($helper->amount) }}</span></div>
                @endforeach
            </div>
        @endif

        <a href="{{ route('products.show', $product) }}" class="btn btn-outline w-full">See the product</a>
    </div>
@endsection
