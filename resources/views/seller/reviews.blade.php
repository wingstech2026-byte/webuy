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
            @if ($review->seller_reply) <p class="mt-2 rounded-lg bg-stone-50 p-2 text-sm"><strong>Your reply:</strong> {{ $review->seller_reply }}</p> @endif
            <details class="mt-2"><summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ $review->seller_reply ? 'Change your reply' : 'Reply' }}</summary>
                <form method="POST" action="{{ route('seller.reviews.reply', ['kind' => 'shop', 'id' => $review->id]) }}" class="mt-2 flex gap-2">@csrf
                    <input name="reply" required maxlength="1000" class="input flex-1" value="{{ $review->seller_reply }}" placeholder="Thank you! / Sorry, next time..." aria-label="Your reply">
                    <button class="btn btn-outline btn-sm">Post</button>
                </form>
            </details>
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
            @if ($review->taste) <p class="text-xs text-stone-500">Taste {{ $review->taste }}/5 &middot; Portion {{ $review->portion ?? '—' }}/5 &middot; Packaging {{ $review->packaging ?? '—' }}/5</p> @endif
            @if ($review->seller_reply) <p class="mt-2 rounded-lg bg-stone-50 p-2 text-sm"><strong>Your reply:</strong> {{ $review->seller_reply }}</p> @endif
            <details class="mt-2"><summary class="cursor-pointer text-sm font-semibold text-brand-700">{{ $review->seller_reply ? 'Change your reply' : 'Reply' }}</summary>
                <form method="POST" action="{{ route('seller.reviews.reply', ['kind' => 'product', 'id' => $review->id]) }}" class="mt-2 flex gap-2">@csrf
                    <input name="reply" required maxlength="1000" class="input flex-1" value="{{ $review->seller_reply }}" placeholder="Thank you! / Sorry, next time..." aria-label="Your reply">
                    <button class="btn btn-outline btn-sm">Post</button>
                </form>
            </details>
        </div>
    @empty
        <p class="text-stone-500">No product reviews yet.</p>
    @endforelse
    <div class="mt-2">{{ $productReviews->links() }}</div>
@endsection
