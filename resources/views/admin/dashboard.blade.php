@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Dashboard</h1>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['Sales today', money($salesToday), $ordersToday.' paid order(s)', route('admin.orders.index')],
            ['Sales this month', money($salesMonth), null, route('admin.orders.index')],
            ['Commission this month', money($commissionMonth), 'From completed orders', null],
            ['Commission (all time)', money($commissionTotal), null, null],
            ['Seller applications', $pendingApplications, $newSellers.' new this week', route('admin.sellers.index', ['status' => 'pending'])],
            ['Open disputes', $openDisputes, null, route('admin.disputes.index')],
            ['Payouts to approve', $pendingPayouts, money($pendingPayoutsAmount), route('admin.payouts.index')],
            ['Failed refunds', $refundsWaiting, 'Retry from the order page', route('admin.orders.index')],
        ] as [$label, $value, $sub, $href])
            <a @if ($href) href="{{ $href }}" @endif class="card block p-4 {{ $href ? 'hover:shadow-md' : '' }}">
                <p class="text-sm font-semibold text-stone-500">{{ $label }}</p>
                <p class="mt-1 text-2xl font-extrabold text-brand-800">{{ $value }}</p>
                @if ($sub) <p class="text-xs text-stone-500">{{ $sub }}</p> @endif
            </a>
        @endforeach
    </div>
@endsection
