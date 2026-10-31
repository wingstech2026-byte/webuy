@props(['product'])

<a href="{{ route('products.show', $product) }}" class="card block overflow-hidden transition hover:shadow-md focus-visible:ring-4 focus-visible:ring-brand-300">
    <div class="relative">
        <x-product-image :product="$product" class="aspect-square w-full" />
        @if ($product->discount_percent > 0)
            <span class="badge absolute left-2 top-2 bg-red-600 text-white">-{{ $product->discount_percent }}%</span>
        @endif
        @if ($product->has_tiers)
            <span class="badge absolute right-2 top-2 bg-amber-400 text-stone-900">Wholesale</span>
        @endif
    </div>
    <div class="space-y-1 p-3">
        <h3 class="line-clamp-2 text-sm font-semibold leading-snug">{{ $product->title }}</h3>
        <p>
            <span class="text-lg font-extrabold text-brand-700">{{ money($product->price) }}</span>
            @if ($product->compare_at_price)
                <span class="text-xs text-stone-400 line-through">{{ money($product->compare_at_price) }}</span>
            @endif
        </p>
        @if ($product->has_tiers)
            <p class="text-xs font-semibold text-amber-700">From {{ money($product->lowest_price) }} in bulk</p>
        @endif
        <p class="truncate text-xs text-stone-500">
            {{ $product->seller->shop_name }}
            @if ($product->seller->is_verified) <span class="font-bold text-brand-700">✓</span> @endif
            &middot; {{ $product->seller->district }}
        </p>
        @if ($product->rating_count > 0)
            <x-stars :rating="$product->rating_avg" :count="$product->rating_count" class="text-xs" />
        @endif
    </div>
</a>
