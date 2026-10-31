@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Payouts</h1>

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
                    <p class="text-sm"><a class="font-semibold text-brand-700" href="{{ route('admin.sellers.show', $payout->seller) }}">{{ $payout->seller->shop_name }}</a> &middot; {{ $payout->operator->label() }} {{ phone_display($payout->phone) }}</p>
                    <p class="text-xs text-stone-500">Requested {{ $payout->created_at->format('j M Y, g:ia') }}</p>
                    @if ($payout->rejection_reason) <p class="text-xs text-red-700">{{ $payout->rejection_reason }}</p> @endif
                </div>
                <span class="badge {{ $payout->status->badge() }}">{{ $payout->status->label() }}</span>
            </div>
            @if ($payout->status === \App\Enums\PayoutStatus::Requested)
                <div class="mt-3 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.payouts.approve', $payout) }}" onsubmit="return confirm('Send {{ money($payout->amount) }} to this seller now?')">@csrf
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
