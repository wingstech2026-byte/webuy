@extends('seller.layout')

@section('seller')
    <h1 class="mb-1 text-2xl font-extrabold">Requests for quotes</h1>
    <p class="mb-5 text-stone-600">Buyers say what they need; send your best price. Your quote locks the price for {{ \App\Support\RewardSettings::int('quote_valid_days') }} days. If the buyer accepts, it becomes an order at that price.</p>

    @forelse ($rfqs as $rfq)
        <div class="card mb-3 p-4">
            <div class="flex flex-wrap justify-between gap-2">
                <div>
                    <p class="font-bold">{{ $rfq->title }}</p>
                    <p class="text-sm text-stone-600">{{ $rfq->quantity }} {{ $rfq->unit }} &middot; deliver to {{ $rfq->district }}
                        @if ($rfq->needed_by) &middot; needed by {{ $rfq->needed_by->format('j M') }} @endif
                        @if ($rfq->target_price) &middot; target {{ money($rfq->target_price) }} each @endif
                        &middot; {{ $rfq->quotes_count }} quote(s)</p>
                    @if ($rfq->description) <p class="mt-1 text-sm">{{ $rfq->description }}</p> @endif
                </div>
                @if (in_array($rfq->id, $quoted, true)) <span class="badge bg-brand-50 text-brand-800">Quoted</span> @endif
            </div>
            @unless (in_array($rfq->id, $quoted, true))
                <form method="POST" action="{{ route('seller.rfqs.quote', $rfq) }}" class="mt-3 grid gap-2 sm:grid-cols-5">
                    @csrf
                    <select name="product_id" class="input sm:col-span-2" required aria-label="Product">
                        @foreach ($products as $p) <option value="{{ $p->id }}">{{ $p->title }}</option> @endforeach
                    </select>
                    <input name="unit_price" type="number" step="0.01" min="0.01" required class="input" placeholder="Price each (Le)" aria-label="Price each">
                    <input name="quantity" type="number" min="1" required class="input" value="{{ $rfq->quantity }}" aria-label="Quantity">
                    <input name="lead_time_days" type="number" min="0" required class="input" value="1" aria-label="Days to deliver">
                    <input name="note" maxlength="500" class="input sm:col-span-4" placeholder="Note (optional): brand, quality, delivery">
                    <button class="btn btn-primary btn-sm">Send quote</button>
                </form>
            @endunless
        </div>
    @empty
        <x-empty title="No open requests">New requests from buyers will show here.</x-empty>
    @endforelse
    {{ $rfqs->links() }}

    @if ($mine->isNotEmpty())
        <h2 class="mb-2 mt-6 text-lg font-extrabold">My quotes</h2>
        @foreach ($mine as $q)
            <div class="card mb-2 flex flex-wrap justify-between gap-2 p-3 text-sm">
                <span><strong>{{ $q->rfq->title }}</strong> &middot; {{ $q->quantity }} &times; {{ money($q->unit_price) }} &middot; valid until {{ $q->valid_until->format('j M') }}</span>
                <span class="badge bg-stone-100">{{ $q->status }}</span>
            </div>
        @endforeach
    @endif
@endsection
