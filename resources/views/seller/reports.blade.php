@extends('seller.layout')

@section('seller')
    @php
        $t = $report['totals'];
        $change = \App\Support\Money::isZero($report['previous_sales']) ? null
            : (int) round(((float) $t['sales'] - (float) $report['previous_sales']) / (float) $report['previous_sales'] * 100);
        $peak = max(1, ...array_map(fn ($w) => (float) $w['sales'], $report['weeks']));
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-extrabold">Sales report</h1>
        <div class="flex gap-2">
            @foreach ($periods as $days => $label)
                <a href="{{ route('seller.reports', ['days' => $days]) }}" class="badge py-2 text-sm {{ $report['days'] === $days ? 'bg-brand-700 text-white' : 'bg-white ring-1 ring-stone-200' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="card p-4">
            <p class="text-sm font-semibold text-stone-500">Sales</p>
            <p class="mt-1 text-xl font-extrabold text-brand-700">{{ money($t['sales']) }}</p>
            @if ($change !== null)
                <p class="text-xs {{ $change >= 0 ? 'text-brand-700' : 'text-red-700' }}">{{ $change >= 0 ? '▲' : '▼' }} {{ abs($change) }}% on the {{ $report['days'] }} days before</p>
            @endif
        </div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">You keep</p><p class="mt-1 text-xl font-extrabold">{{ money($t['kept']) }}</p><p class="text-xs text-stone-500">After WiBuy's commission</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Orders</p><p class="mt-1 text-xl font-extrabold">{{ $t['orders'] }}</p><p class="text-xs text-stone-500">Average {{ money($t['average_order']) }}</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Items sold</p><p class="mt-1 text-xl font-extrabold">{{ $t['items_sold'] }}</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Buyers</p><p class="mt-1 text-xl font-extrabold">{{ $t['buyers'] }}</p><p class="text-xs text-stone-500">{{ $t['repeat_buyers'] }} bought more than once</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Lost orders</p><p class="mt-1 text-xl font-extrabold">{{ $t['lost_orders'] }}</p><p class="text-xs text-stone-500">Refused, cancelled or refunded</p></div>
    </div>

    <h2 class="mb-2 mt-8 text-lg font-extrabold">Sales week by week</h2>
    <div class="card p-4">
        <div class="flex h-48 items-end gap-2" role="img" aria-label="Sales for the last {{ count($report['weeks']) }} weeks">
            @foreach ($report['weeks'] as $week)
                <div class="flex h-full flex-1 flex-col items-center justify-end gap-1" title="Week of {{ $week['label'] }}: {{ money($week['sales']) }}, {{ $week['orders'] }} orders">
                    <span class="text-[10px] font-semibold text-stone-500">{{ (float) $week['sales'] > 0 ? number_format((float) $week['sales']) : '' }}</span>
                    <div class="w-full rounded-t bg-brand-600" style="height: {{ max(2, round((float) $week['sales'] / $peak * 100)) }}%"></div>
                </div>
            @endforeach
        </div>
        <div class="mt-1 flex gap-2 border-t border-stone-200 pt-1">
            @foreach ($report['weeks'] as $week)
                <span class="flex-1 text-center text-[10px] text-stone-500">{{ $week['label'] }}</span>
            @endforeach
        </div>
    </div>

    <div class="mt-8 grid gap-6 md:grid-cols-2">
        <section>
            <h2 class="mb-2 text-lg font-extrabold">Best sellers</h2>
            <div class="card divide-y divide-stone-100">
                @forelse ($report['best_sellers'] as $i => $row)
                    <div class="flex items-center justify-between gap-3 p-3 text-sm">
                        <span><strong>{{ $i + 1 }}.</strong> {{ $row['title'] }}</span>
                        <span class="whitespace-nowrap text-right"><strong>{{ $row['quantity'] }} sold</strong><span class="block text-xs text-stone-500">{{ money($row['sales']) }}</span></span>
                    </div>
                @empty
                    <p class="p-4 text-sm text-stone-500">No sales in this period yet.</p>
                @endforelse
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-extrabold">Running low</h2>
            <div class="card divide-y divide-stone-100">
                @forelse ($report['low_stock'] as $product)
                    <a href="{{ route('seller.products.edit', $product['slug']) }}" class="flex items-center justify-between gap-3 p-3 text-sm hover:bg-brand-50">
                        <span>{{ $product['title'] }}</span>
                        <span class="badge {{ $product['stock'] === 0 ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800' }}">{{ $product['stock'] === 0 ? 'Sold out' : $product['stock'].' left' }}</span>
                    </a>
                @empty
                    <p class="p-4 text-sm text-stone-500">All your live products have more than {{ \App\Services\SellerReportService::LOW_STOCK }} in stock.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
