@extends('seller.layout')

@section('seller')
    <h1 class="mb-4 text-2xl font-extrabold">Earnings</h1>

    <div class="grid gap-3 sm:grid-cols-4">
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Pending</p><p class="mt-1 text-xl font-extrabold">{{ money($money['pending']) }}</p><p class="text-xs text-stone-500">Orders not completed yet</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Available</p><p class="mt-1 text-xl font-extrabold text-brand-700">{{ money($money['available']) }}</p><p class="text-xs text-stone-500">You can ask for this now</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Payouts on the way</p><p class="mt-1 text-xl font-extrabold">{{ money($money['in_transit']) }}</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Paid out</p><p class="mt-1 text-xl font-extrabold">{{ money($money['paid_out']) }}</p></div>
    </div>

    <form method="POST" action="{{ route('seller.payouts.store') }}" class="card mt-4 space-y-3 p-4">
        @csrf
        <h2 class="font-bold">Request a payout</h2>
        <p class="text-sm text-stone-600">
            We send it to {{ $seller->payout_operator->label() }} {{ phone_display($seller->payout_phone) }} after a quick check.
            Minimum {{ money($minPayout) }}. <a href="{{ route('seller.shop') }}" class="underline">Change number</a>
        </p>
        <div class="flex flex-wrap gap-2">
            <label for="amount" class="sr-only">Amount</label>
            <input id="amount" name="amount" type="number" step="0.01" min="0.01" inputmode="decimal" class="input max-w-48" value="{{ old('amount', $money['available']) }}">
            <button class="btn btn-primary btn-sm" @disabled(\App\Support\Money::cmp($money['available'], $minPayout) < 0)>Request payout</button>
        </div>
        @error('amount') <p class="field-error">{{ $message }}</p> @enderror
        @if (\App\Support\Money::cmp($money['available'], $minPayout) < 0)
            <p class="text-xs text-stone-500">You need at least {{ money($minPayout) }} available to request a payout.</p>
        @endif
    </form>

    <h2 class="mb-2 mt-8 text-lg font-extrabold">Payouts</h2>
    <div class="card overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-stone-200"><tr><th class="th">Requested</th><th class="th text-right">Amount</th><th class="th">Status</th></tr></thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($payouts as $payout)
                    <tr>
                        <td class="td">{{ $payout->created_at->format('j M Y, g:ia') }}</td>
                        <td class="td text-right font-semibold">{{ money($payout->amount) }}</td>
                        <td class="td"><span class="badge {{ $payout->status->badge() }}">{{ $payout->status->label() }}</span>
                            @if ($payout->rejection_reason) <span class="block text-xs text-stone-500">{{ $payout->rejection_reason }}</span> @endif</td>
                    </tr>
                @empty
                    <tr><td class="td text-stone-500" colspan="3">No payouts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-2">{{ $payouts->links() }}</div>

    <h2 class="mb-2 mt-8 text-lg font-extrabold">Every credit and debit</h2>
    <div class="card overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-stone-200"><tr><th class="th">Date</th><th class="th">What</th><th class="th text-right">Amount</th></tr></thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($entries as $entry)
                    <tr>
                        <td class="td whitespace-nowrap">{{ $entry->created_at->format('j M Y') }}</td>
                        <td class="td">{{ $entry->type->label() }}<span class="block text-xs text-stone-500">{{ $entry->description }}</span></td>
                        <td class="td text-right font-semibold {{ \App\Support\Money::cmp($entry->amount, 0) < 0 ? 'text-red-700' : 'text-brand-700' }}">{{ money($entry->amount) }}</td>
                    </tr>
                @empty
                    <tr><td class="td text-stone-500" colspan="3">Nothing yet. Money is added here when an order completes.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-2">{{ $entries->links() }}</div>
@endsection
