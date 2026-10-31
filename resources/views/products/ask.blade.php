@extends('layouts.app')

@section('title', 'Ask the seller')

@section('content')
    <div class="mx-auto max-w-lg space-y-4">
        <a href="{{ route('products.show', $product) }}" class="text-sm font-semibold text-brand-700">&larr; {{ $product->title }}</a>

        <div class="card p-6 text-center">
            <p class="text-5xl" aria-hidden="true">💬</p>
            <h1 class="mt-2 text-2xl font-extrabold">Chat with sellers is coming soon</h1>
            <p class="mt-2 text-stone-600">
                Soon you will be able to message {{ $seller->shop_name }} right here.
                Until then, the WiBuy team can help with any question about this product.
            </p>
            <a href="{{ route('chat.index') }}" class="btn btn-primary mt-4">Ask the WiBuy team</a>
        </div>

        <x-seller-card :seller="$seller" />

        <div class="card p-4 text-sm text-stone-600">
            <p><strong>Pickup address:</strong> {{ $seller->pickup_address }}, {{ $seller->location }}</p>
        </div>
    </div>
@endsection
