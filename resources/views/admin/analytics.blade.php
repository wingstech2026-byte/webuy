@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Sales, last 30 days</h1>

    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([['Sales', money($sales)], ['Orders', $orders], ['Average order', money($averageOrder)], ['Commission earned', money($commission)], ['New people', $newBuyers], ['New shops', $newShops]] as [$label, $value])
            <div class="card p-4"><p class="text-sm font-semibold text-stone-500">{{ $label }}</p><p class="mt-1 text-xl font-extrabold">{{ $value }}</p></div>
        @endforeach
    </div>

    <div class="card mt-6 p-4">
        <h2 class="mb-3 font-bold">Sales per day</h2>
        <div class="flex h-48 items-end gap-1" role="img" aria-label="Sales per day for the last 30 days">
            @foreach ($days as $day)
                <div class="group relative flex-1 rounded-t bg-brand-600 hover:bg-brand-800" style="height: {{ max(1, round((float) $day['sales'] / $maxSales * 100)) }}%"
                     title="{{ \Illuminate\Support\Carbon::parse($day['day'])->format('j M') }}: {{ money($day['sales']) }}, {{ $day['orders'] }} order(s)"></div>
            @endforeach
        </div>
        <div class="mt-1 flex justify-between text-xs text-stone-500">
            <span>{{ \Illuminate\Support\Carbon::parse($days->first()['day'])->format('j M') }}</span>
            <span>{{ \Illuminate\Support\Carbon::parse($days->last()['day'])->format('j M') }}</span>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        @foreach (['Best categories' => $topCategories, 'Best shops' => $topShops] as $title => $rows)
            <div class="card p-4">
                <h2 class="mb-3 font-bold">{{ $title }}</h2>
                @php($top = max(1, (float) ($rows->max('sales') ?? 1)))
                @forelse ($rows as $row)
                    <div class="mb-2">
                        <div class="flex justify-between text-sm"><span class="font-semibold">{{ $row->name }}</span><span>{{ money($row->sales) }}</span></div>
                        <div class="h-2 rounded bg-stone-100"><div class="h-2 rounded bg-brand-600" style="width: {{ round((float) $row->sales / $top * 100) }}%"></div></div>
                    </div>
                @empty
                    <p class="text-sm text-stone-500">No sales yet.</p>
                @endforelse
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        @foreach (['Paid, not accepted for over a day' => [$notAccepted, 'paid_at'], 'Accepted, not sent for over 3 days' => [$notShipped, 'accepted_at']] as $title => [$rows, $since])
            <div class="card p-4">
                <h2 class="mb-3 font-bold">⏰ {{ $title }} ({{ $rows->count() }})</h2>
                @forelse ($rows as $order)
                    <a href="{{ route('admin.orders.show', $order) }}" class="flex justify-between border-b border-stone-100 py-1 text-sm hover:bg-stone-50">
                        <span>{{ $order->number }} · {{ $order->seller->shop_name }}</span><span class="text-stone-500">{{ $order->{$since}->diffForHumans() }}</span>
                    </a>
                @empty
                    <p class="text-sm text-stone-500">None. 👍</p>
                @endforelse
            </div>
        @endforeach
    </div>
@endsection
