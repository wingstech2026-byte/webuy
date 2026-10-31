@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Disputes</h1>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (['open' => 'Open', 'resolved_buyer' => 'Buyer refunded', 'resolved_seller' => 'Seller paid', 'all' => 'All'] as $value => $label)
            <a href="{{ route('admin.disputes.index', ['status' => $value]) }}" class="badge py-2 text-sm {{ $status === $value ? 'bg-brand-700 text-white' : 'bg-white ring-1 ring-stone-200' }}">{{ $label }}</a>
        @endforeach
    </div>

    @forelse ($items as $dispute)
        <a href="{{ route('disputes.show', $dispute) }}" class="card mb-2 block p-4 hover:shadow-md">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="font-bold">{{ $dispute->order->number }} &middot; {{ money($dispute->order->total) }}</p>
                    <p class="text-sm text-stone-600">{{ $dispute->buyer->name }} vs {{ $dispute->seller->shop_name }} &middot; {{ $dispute->reason }}</p>
                    <p class="text-xs text-stone-500">{{ $dispute->created_at->diffForHumans() }} &middot; {{ $dispute->seller_response ? 'Seller replied' : 'No reply from seller yet' }}</p>
                </div>
                <span class="badge {{ $dispute->status->badge() }}">{{ $dispute->status->label() }}</span>
            </div>
        </a>
    @empty
        <x-empty title="No disputes here">Nothing to decide right now.</x-empty>
    @endforelse
    <div class="mt-4">{{ $items->links() }}</div>
@endsection
