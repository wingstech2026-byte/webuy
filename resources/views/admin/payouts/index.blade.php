@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Payouts</h1>

    {{-- A correction on a shop's or rider's balance. The Riders page links here with the fields filled in. --}}
    <details class="card mb-4 p-4" @if ($errors->hasAny(['for', 'phone', 'amount', 'reason']) || request()->has('phone')) open @endif>
        <summary class="cursor-pointer font-bold">Adjust a balance</summary>
        <form method="POST" action="{{ route('admin.payouts.adjust') }}" class="mt-3 grid gap-3 sm:grid-cols-2">
            @csrf
            <div><label class="label" for="adj-for">Whose balance</label>
                <select id="adj-for" name="for" class="input">
                    <option value="seller" @selected(old('for', request('for')) === 'seller')>A shop</option>
                    <option value="rider" @selected(old('for', request('for')) === 'rider')>A rider</option>
                </select></div>
            <div><label class="label" for="adj-phone">Their phone number</label>
                <input id="adj-phone" name="phone" inputmode="tel" required class="input" value="{{ old('phone', request('phone')) }}" placeholder="076 123 456">
                @error('phone') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="adj-amount">Amount (20 adds, -20 takes away)</label>
                <input id="adj-amount" name="amount" required class="input" value="{{ old('amount', request('amount')) }}">
                @error('amount') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="adj-reason">Reason (they see it)</label>
                <input id="adj-reason" name="reason" required maxlength="150" class="input" value="{{ old('reason', request('reason')) }}">
                @error('reason') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2"><button class="btn btn-primary btn-sm" onclick="return confirm('Change this balance?')">Save adjustment</button></div>
        </form>
    </details>

    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (\App\Enums\PayoutStatus::cases() as $case)
            <a href="{{ route('admin.payouts.index', ['status' => $case->value]) }}" class="badge py-2 text-sm {{ $status === $case ? 'bg-brand-700 text-white' : 'bg-white ring-1 ring-stone-200' }}">{{ $case->label() }}</a>
        @endforeach
    </div>

    @forelse ($items as $payout)
        <div class="card mb-2 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-lg font-extrabold">{{ money($payout->amount) }}</p>
                    <p class="text-sm">@if ($payout->rider) <span class="font-semibold">{{ $payout->recipientName() }}</span> @else <a class="font-semibold text-brand-700" href="{{ route('admin.sellers.show', $payout->seller) }}">{{ $payout->seller->shop_name }}</a> @endif &middot; {{ $payout->operator->label() }} {{ phone_display($payout->phone) }}</p>
                    <p class="text-xs text-stone-500">Requested {{ $payout->created_at->format('j M Y, g:ia') }}</p>
                    @if ($payout->rejection_reason) <p class="text-xs text-red-700">{{ $payout->rejection_reason }}</p> @endif
                </div>
                <span class="badge {{ $payout->status->badge() }}">{{ $payout->status->label() }}</span>
            </div>
            @if ($payout->status === \App\Enums\PayoutStatus::Requested)
                <div class="mt-3 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.payouts.approve', $payout) }}" onsubmit="return confirm('Send {{ money($payout->amount) }} to {{ $payout->rider ? 'this rider' : 'this seller' }} now?')">@csrf
                        <button class="btn btn-primary btn-sm">Approve and send</button>
                    </form>
                    <form method="POST" action="{{ route('admin.payouts.reject', $payout) }}" class="flex flex-wrap gap-2">@csrf
                        <input name="reason" maxlength="200" class="input min-h-10 max-w-56 py-1" placeholder="Reason (optional)" aria-label="Reason">
                        <button class="btn btn-danger btn-sm">Reject</button>
                    </form>
                </div>
            @endif
        </div>
    @empty
        <x-empty title="Nothing here">No payouts with this status.</x-empty>
    @endforelse
    <div class="mt-4">{{ $items->links() }}</div>
@endsection
