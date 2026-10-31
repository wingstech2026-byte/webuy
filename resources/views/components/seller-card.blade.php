@props(['seller'])

<a href="{{ route('shops.show', $seller) }}" {{ $attributes->merge(['class' => 'card flex items-center gap-3 p-4 transition hover:shadow-md']) }}>
    <x-shop-logo :seller="$seller" class="size-14 shrink-0 rounded-full" />
    <span class="min-w-0">
        <strong class="flex items-center gap-1 truncate">
            {{ $seller->shop_name }}
            @if ($seller->is_verified)
                <span class="badge bg-brand-100 text-brand-800" title="WiBuy checked this seller">✓ Verified</span>
            @endif
        </strong>
        <span class="block text-sm text-stone-500">{{ $seller->location }}</span>
        <x-stars :rating="$seller->rating_avg" :count="$seller->rating_count" class="text-xs" />
    </span>
</a>
