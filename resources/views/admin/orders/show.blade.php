@extends('admin.layout')

@section('admin')
    <a href="{{ route('admin.orders.index') }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; Orders</a>

    <div class="mx-auto max-w-3xl space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-2xl font-extrabold">Order {{ $order->number }}</h1>
                <p class="text-stone-600">{{ $order->buyer->name }} ({{ $order->buyer->display_phone }}) &rarr; <a class="text-brand-700" href="{{ route('admin.sellers.show', $order->seller) }}">{{ $order->seller->shop_name }}</a></p>
            </div>
            <span class="badge {{ $order->status->badge() }} text-sm">{{ $order->statusLabel() }}</span>
        </div>

        <div class="card divide-y divide-stone-100 text-sm">
            @foreach ($order->items as $item)
                <div class="flex justify-between p-3"><span>{{ $item->quantity }} &times; {{ $item->title }} @ {{ money($item->unit_price) }}</span><strong>{{ money($item->line_total) }}</strong></div>
            @endforeach
            <div class="flex justify-between p-3"><span>Delivery ({{ $order->isPickup() ? 'pickup' : $order->district }})</span><span>{{ money($order->delivery_fee) }}</span></div>
            <div class="flex justify-between p-3"><span>Total</span><strong>{{ money($order->total) }}</strong></div>
            <div class="flex justify-between p-3"><span>Commission ({{ $order->commission_percent }}%)</span><span>{{ money($order->commission) }}</span></div>
            <div class="flex justify-between p-3"><span>Seller gets on completion</span><span>{{ money($order->seller_earnings) }}</span></div>
        </div>

        <dl class="card grid gap-2 p-4 text-sm sm:grid-cols-2">
            @foreach (['paid_at' => 'Paid', 'accept_by' => 'Accept by', 'accepted_at' => 'Accepted', 'rejected_at' => 'Rejected', 'shipped_at' => 'Shipped', 'delivered_at' => 'Delivered', 'completed_at' => 'Completed', 'cancelled_at' => 'Cancelled', 'refunded_at' => 'Refunded'] as $field => $label)
                @if ($order->{$field}) <div><dt class="font-bold text-stone-500">{{ $label }}</dt><dd>{{ $order->{$field}->format('j M Y, g:ia') }}</dd></div> @endif
            @endforeach
            @if ($order->rejection_reason) <div class="sm:col-span-2"><dt class="font-bold text-stone-500">Rejection reason</dt><dd>{{ $order->rejection_reason }}</dd></div> @endif
            @if ($order->refund_reason) <div class="sm:col-span-2"><dt class="font-bold text-stone-500">Refund reason</dt><dd>{{ $order->refund_reason }}</dd></div> @endif
        </dl>

        <h2 class="text-lg font-extrabold">Payments</h2>
        <div class="card overflow-x-auto">
            <table class="w-full">
                <thead class="border-b border-stone-200"><tr><th class="th">Kind</th><th class="th">Reference</th><th class="th text-right">Amount</th><th class="th">Status</th></tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($order->checkout->payments()->where(fn ($q) => $q->whereNull('order_id')->orWhere('order_id', $order->id))->get() as $payment)
                        <tr>
                            <td class="td">{{ ucfirst($payment->kind->value) }}<span class="block text-xs text-stone-500">{{ $payment->operator->label() }} {{ phone_display($payment->phone) }}</span></td>
                            <td class="td font-mono text-xs">{{ $payment->provider_reference }}</td>
                            <td class="td text-right">{{ money($payment->amount) }}</td>
                            <td class="td">{{ $payment->status->value }} @if ($payment->raw_response['reason'] ?? null) <span class="block text-xs text-red-700">{{ $payment->raw_response['reason'] }}</span> @endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($order->payments->where('kind', \App\Enums\PaymentKind::Refund)->last()?->status === \App\Enums\PaymentStatus::Failed)
            <form method="POST" action="{{ route('admin.orders.retry-refund', $order) }}">@csrf
                <button class="btn btn-danger">Retry the refund</button>
            </form>
        @endif

        @foreach ($order->disputes as $dispute)
            <a href="{{ route('disputes.show', $dispute) }}" class="card block p-4 hover:shadow-md">Dispute: {{ $dispute->reason }} <span class="badge {{ $dispute->status->badge() }}">{{ $dispute->status->label() }}</span></a>
        @endforeach
    </div>
@endsection
