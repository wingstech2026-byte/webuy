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
            <h2 class="mb-2 text-lg font-extrabold">⏳ Will run out soon</h2>
            <div class="card divide-y divide-stone-100">
                @forelse ($report['forecast'] as $row)
                    <a href="{{ route('seller.products.edit', $row['slug']) }}" class="flex items-center justify-between gap-3 p-3 text-sm hover:bg-brand-50">
                        <span>{{ $row['title'] }} <span class="text-stone-500">&middot; sells about {{ $row['per_day'] }} a day</span></span>
                        <span class="badge {{ $row['days_left'] <= 3 ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800' }}">{{ $row['days_left'] < 1 ? 'Out today' : 'about '.$row['days_left'].' day(s) left' }}</span>
                    </a>
                @empty
                    <p class="p-4 text-sm text-stone-500">Nothing runs out in the next 2 weeks at the current pace.</p>
                @endforelse
            </div>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-extrabold">👀 Looked at → in cart → bought</h2>
            <div class="card overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left"><th class="th">Product</th><th class="th text-right">Viewers</th><th class="th text-right">Carts</th><th class="th text-right">Orders</th><th class="th text-right">Bought / viewers</th></tr></thead>
                    <tbody>
                        @forelse ($report['funnel'] as $row)
                            <tr><td class="td">{{ $row['title'] }}</td><td class="td text-right">{{ $row['views'] }}</td><td class="td text-right">{{ $row['carts'] }}</td><td class="td text-right">{{ $row['orders'] }}</td><td class="td text-right">{{ $row['conversion'] === null ? '—' : $row['conversion'].'%' }}</td></tr>
                        @empty
                            <tr><td class="td" colspan="5">No visits yet in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-1 text-xs text-stone-500">Viewers are logged-in shoppers who opened the product. Many viewers but few orders? Check the price, photos and description.</p>
        </section>

        <section>
            <h2 class="mb-2 text-lg font-extrabold">🕐 When buyers order</h2>
            @php($topHour = max(1, max($report['hours'])))
            <div class="card p-4">
                <div class="flex h-32 items-end gap-0.5" role="img" aria-label="Orders per hour of the day">
                    @foreach ($report['hours'] as $hour => $count)
                        <div class="flex-1 rounded-t {{ $count === max($report['hours']) && $count > 0 ? 'bg-brand-700' : 'bg-brand-300' }}" style="height: {{ max(2, round($count / $topHour * 100)) }}%" title="{{ sprintf('%02d:00', $hour) }}: {{ $count }} order(s)"></div>
                    @endforeach
                </div>
                <div class="mt-1 flex justify-between text-xs text-stone-500"><span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:00</span></div>
                @if (max($report['hours']) > 0)
                    <p class="mt-2 text-sm">Most orders come around <strong>{{ sprintf('%02d:00', array_search(max($report['hours']), $report['hours'])) }}</strong>: a good time for offers and live shows.</p>
                @endif
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
