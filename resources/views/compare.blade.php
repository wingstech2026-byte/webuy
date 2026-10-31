@extends('layouts.app')

@section('title', 'Compare products')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-extrabold">Compare products</h1>
        @if ($products->isNotEmpty())
            <form method="POST" action="{{ route('compare.clear') }}">@csrf<button class="btn btn-outline btn-sm">Clear</button></form>
        @endif
    </div>

    @if ($products->isEmpty())
        <p class="card p-4">Nothing to compare yet. On a product page, tap <strong>⚖️ Compare</strong> on up to {{ \App\Support\BrowsingHistory::COMPARE_MAX }} products.</p>
    @else
        @php
            $rows = [
                'Price' => fn ($p) => money($p->price).($p->compare_at_price ? ' (was '.money($p->compare_at_price).')' : ''),
                'Bulk prices' => fn ($p) => $p->tiers->where('business_only', false)->map(fn ($t) => $t->min_quantity.'+: '.money($t->unit_price))->implode(', ') ?: '—',
                'Minimum order' => fn ($p) => $p->min_order_quantity.' '.$p->unit,
                'In stock' => fn ($p) => $p->stock > 0 ? $p->stock.' '.$p->unit : 'Out of stock',
                'Options' => fn ($p) => $p->variants->where('is_active', true)->pluck('name')->implode(', ') ?: '—',
                'Condition' => fn ($p) => ucfirst($p->condition->value),
                'Rating' => fn ($p) => $p->rating_count ? '★ '.number_format((float) $p->rating_avg, 1).' ('.$p->rating_count.')' : 'No ratings yet',
                'Sold' => fn ($p) => $p->sold_count,
                'Shop' => fn ($p) => $p->seller->shop_name.($p->seller->is_verified ? ' ✓ verified' : ''),
                'Shop is in' => fn ($p) => $p->seller->district ?? '—',
                'Delivers to' => fn ($p) => $p->seller->offers_delivery ? ($p->seller->deliveryAreas->count().' district(s), from '.money($p->seller->deliveryAreas->min('fee'))) : 'Pickup only',
                'Ready in' => fn ($p) => $p->lead_time_days ? $p->lead_time_days.' days (made to order)' : 'In stock now',
            ];
        @endphp
        <div class="card overflow-x-auto">
            <table class="w-full min-w-[36rem] text-sm">
                <thead>
                    <tr>
                        <th class="th w-32"></th>
                        @foreach ($products as $p)
                            <th class="th align-top">
                                <a href="{{ route('products.show', $p) }}" class="block space-y-2 text-left">
                                    <x-product-image :product="$p" class="aspect-square w-full max-w-40 rounded-lg object-cover" />
                                    <span class="line-clamp-2 font-bold">{{ $p->title }}</span>
                                </a>
                                <form method="POST" action="{{ route('compare.toggle', $p) }}" class="mt-1">@csrf<button class="text-xs font-semibold text-red-700">Remove</button></form>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $label => $value)
                        <tr>
                            <th class="td text-left font-semibold text-stone-600">{{ $label }}</th>
                            @foreach ($products as $p) <td class="td align-top">{{ $value($p) }}</td> @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($recent->isNotEmpty())
        <h2 class="mb-2 mt-8 text-lg font-extrabold">Recently viewed</h2>
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($recent as $product) <x-product-card :product="$product" /> @endforeach
        </div>
    @endif
@endsection
