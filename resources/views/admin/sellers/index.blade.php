@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Sellers</h1>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (\App\Enums\SellerStatus::cases() as $case)
            <a href="{{ route('admin.sellers.index', ['status' => $case->value]) }}"
               class="badge py-2 text-sm {{ $status === $case ? 'bg-brand-700 text-white' : 'bg-white ring-1 ring-stone-200' }}">{{ $case->label() }} ({{ $counts[$case->value] ?? 0 }})</a>
        @endforeach
    </div>

    @forelse ($items as $seller)
        <a href="{{ route('admin.sellers.show', $seller) }}" class="card mb-2 flex items-center gap-3 p-4 hover:shadow-md">
            <x-shop-logo :seller="$seller" class="size-12 shrink-0 rounded-full" />
            <span class="min-w-0 flex-1">
                <strong>{{ $seller->shop_name }}</strong> @if ($seller->is_verified) <span class="badge bg-brand-100 text-brand-800">✓ Verified</span> @endif
                <span class="block text-sm text-stone-600">{{ $seller->user->name }} &middot; {{ $seller->user->display_phone }} &middot; {{ $seller->location }}</span>
                <span class="block text-xs text-stone-500">Applied {{ $seller->created_at->format('j M Y') }}</span>
            </span>
            <span class="badge {{ $seller->status->badge() }}">{{ $seller->status->label() }}</span>
        </a>
    @empty
        <x-empty title="Nothing here">No sellers with this status.</x-empty>
    @endforelse
    <div class="mt-4">{{ $items->links() }}</div>
@endsection
