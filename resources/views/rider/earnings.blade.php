@extends('layouts.app')

@section('title', 'My earnings')

@section('content')
    <a href="{{ route('rider.index') }}" class="text-sm font-semibold text-brand-700">&larr; Rider jobs</a>
    <h1 class="mb-1 mt-2 text-2xl font-extrabold">💰 My earnings</h1>
    <p class="mb-4 text-stone-600">You earn the delivery fee once the buyer confirms the order (or it completes by itself a few days after delivery).</p>

    <div class="grid gap-3 grid-cols-2 sm:grid-cols-4">
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Pending</p><p class="mt-1 text-xl font-extrabold">{{ money($money['pending']) }}</p><p class="text-xs text-stone-500">Delivered, not confirmed yet</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Available</p><p class="mt-1 text-xl font-extrabold text-brand-700">{{ money($money['available']) }}</p><p class="text-xs text-stone-500">You can withdraw this now</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Payouts on the way</p><p class="mt-1 text-xl font-extrabold">{{ money($money['in_transit']) }}</p></div>
        <div class="card p-4"><p class="text-sm font-semibold text-stone-500">Paid out</p><p class="mt-1 text-xl font-extrabold">{{ money($money['paid_out']) }}</p></div>
    </div>

    @php($tooLittle = \App\Support\Money::cmp($money['available'], $minPayout) < 0)
    <form method="POST" action="{{ route('rider.payouts.store') }}" class="card mt-4 space-y-3 p-4">
        @csrf
        <h2 class="font-bold">Withdraw to mobile money</h2>
        <p class="text-sm text-stone-600">We send it after a quick check. Minimum {{ money($minPayout) }}.</p>
        <div class="grid gap-2 sm:grid-cols-3">
            <div>
                <label for="operator" class="label">Pay with</label>
                <select id="operator" name="operator" class="input">
                    @foreach (\App\Enums\Operator::cases() as $op)
                        <option value="{{ $op->value }}" @selected(old('operator', $payTo['operator']?->value) === $op->value)>{{ $op->label() }}</option>
                    @endforeach
                </select>
                @error('operator') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="phone" class="label">Mobile money number</label>
                <input id="phone" name="phone" type="tel" inputmode="tel" class="input" value="{{ old('phone', phone_display($payTo['phone'])) }}">
                @error('phone') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="amount" class="label">Amount</label>
                <input id="amount" name="amount" type="number" step="0.01" min="0.01" inputmode="decimal" class="input" value="{{ old('amount', $money['available']) }}">
                @error('amount') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <button class="btn btn-primary btn-sm" @disabled($tooLittle)>Withdraw</button>
        @if ($tooLittle)
            <p class="text-xs text-stone-500">You need at least {{ money($minPayout) }} available to withdraw.</p>
        @endif
    </form>

    <h2 class="mb-2 mt-8 text-lg font-extrabold">Payouts</h2>
    <div class="card overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-stone-200"><tr><th class="th">Requested</th><th class="th text-right">Amount</th><th class="th">Status</th></tr></thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($payouts as $payout)
                    <tr>
                        <td class="td">{{ $payout->created_at->format('j M Y, g:ia') }}<span class="block text-xs text-stone-500">{{ $payout->operator->label() }} {{ phone_display($payout->phone) }}</span></td>
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
                    <tr><td class="td text-stone-500" colspan="3">Nothing yet. Delivery fees are added here when an order completes.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-2">{{ $entries->links() }}</div>
@endsection
