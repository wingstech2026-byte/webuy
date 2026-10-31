@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Orders</h1>

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        <input type="search" name="q" value="{{ $term }}" placeholder="Order number, e.g. WB-ABC1234" class="input max-w-xs">
        <select name="status" class="input max-w-56" aria-label="Status">
            <option value="">Any status</option>
            @foreach (\App\Enums\OrderStatus::cases() as $case)
                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
            @endforeach
        </select>
        <button class="btn btn-outline btn-sm">Filter</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-stone-200"><tr><th class="th">Order</th><th class="th">Buyer</th><th class="th">Seller</th><th class="th text-right">Total</th><th class="th text-right">Commission</th><th class="th">Status</th></tr></thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($items as $order)
                    <tr>
                        <td class="td"><a href="{{ route('admin.orders.show', $order) }}" class="font-bold text-brand-700">{{ $order->number }}</a><span class="block text-xs text-stone-500">{{ $order->created_at->format('j M, g:ia') }}</span></td>
                        <td class="td">{{ $order->buyer->name }}<span class="block text-xs text-stone-500">{{ $order->buyer->display_phone }}</span></td>
                        <td class="td">{{ $order->seller->shop_name }}</td>
                        <td class="td text-right">{{ money($order->total) }}</td>
                        <td class="td text-right">{{ money($order->commission) }}</td>
                        <td class="td"><span class="badge {{ $order->status->badge() }}">{{ $order->statusLabel() }}</span></td>
                    </tr>
                @empty
                    <tr><td class="td text-stone-500" colspan="6">No orders found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $items->links() }}</div>
@endsection
