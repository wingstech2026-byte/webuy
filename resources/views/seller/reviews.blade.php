@extends('seller.layout')

@section('seller')
    <h1 class="mb-1 text-2xl font-extrabold">Ratings</h1>
    <x-stars :rating="$seller->rating_avg" :count="$seller->rating_count" class="mb-4 text-base" />

    <h2 class="mb-2 mt-4 text-lg font-extrabold">About your shop</h2>
    @forelse ($shopReviews as $review)
        <div class="card mb-2 p-4">
            <div class="flex items-center justify-between gap-2"><strong>{{ $review->buyer->first_name }}</strong><x-stars :rating="$review->rating" /></div>
            @if ($review->comment) <p class="mt-1">{{ $review->comment }}</p> @endif
            <p class="text-xs text-stone-400">{{ $review->created_at->format('j M Y') }}</p>
        </div>
    @empty
        <p class="text-stone-500">No ratings yet. Buyers can rate you after an order completes.</p>
    @endforelse
    <div class="mt-2">{{ $shopReviews->links() }}</div>

    <h2 class="mb-2 mt-8 text-lg font-extrabold">About your products</h2>
    @forelse ($productReviews as $review)
        <div class="card mb-2 p-4">
            <div class="flex items-center justify-between gap-2"><strong>{{ $review->product->title }}</strong><x-stars :rating="$review->rating" /></div>
            <p class="text-sm text-stone-500">{{ $review->buyer->first_name }}</p>
            @if ($review->comment) <p class="mt-1">{{ $review->comment }}</p> @endif
        </div>
    @empty
        <p class="text-stone-500">No product reviews yet.</p>
    @endforelse
    <div class="mt-2">{{ $productReviews->links() }}</div>
@endsection
