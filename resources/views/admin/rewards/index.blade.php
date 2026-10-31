@extends('admin.layout')

@section('admin')
    <h1 class="mb-3 text-2xl font-extrabold">Rewards</h1>
    @include('admin.rewards._nav')

    <div class="mb-6 grid gap-3 sm:grid-cols-3">
        <div class="card p-4"><p class="text-sm text-stone-500">WiBuy Points</p><p class="text-xl font-extrabold">{{ number_format($points['balance']) }}</p><p class="text-xs text-stone-500">{{ number_format($points['earned']) }} earned &middot; {{ number_format($points['spent']) }} exchanged</p></div>
        <div class="card p-4"><p class="text-sm text-stone-500">Shares</p><p class="text-xl font-extrabold">{{ number_format($shares['opened']) }} opened</p><p class="text-xs text-stone-500">{{ number_format($shares['made']) }} links &middot; {{ number_format($shares['signups']) }} friends joined</p></div>
        <div class="card p-4"><p class="text-sm text-stone-500">WiBuy Pass</p><p class="text-xl font-extrabold">{{ number_format($activeMembers) }} members</p><p class="text-xs text-stone-500">{{ money($passIncome) }} paid in total</p></div>
    </div>

    <h2 class="mb-2 text-lg font-extrabold">Coupons</h2>
    <div class="card mb-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Source</th><th class="th text-right">Issued</th><th class="th text-right">Used</th><th class="th text-right">Cost to WiBuy</th></tr></thead>
            <tbody>
                @forelse ($coupons as $row)
                    <tr><td class="td">{{ str_replace('_', ' ', $row->source) }}</td><td class="td text-right">{{ $row->issued }}</td><td class="td text-right">{{ $row->used }}</td><td class="td text-right">{{ money($row->cost) }}</td></tr>
                @empty
                    <tr><td class="td" colspan="4">No coupons yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="mb-2 text-lg font-extrabold">Latest WiBuy Pass payments</h2>
    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Member</th><th class="th">Status</th><th class="th">From</th><th class="th">Until</th><th class="th text-right">Paid</th></tr></thead>
            <tbody>
                @forelse ($memberships as $m)
                    <tr><td class="td">{{ $m->user->name }} ({{ phone_display($m->user->phone) }})</td><td class="td">{{ $m->status }}</td><td class="td">{{ $m->starts_at?->format('j M') }}</td><td class="td">{{ $m->ends_at?->format('j M Y') }}</td><td class="td text-right">{{ money($m->price) }}</td></tr>
                @empty
                    <tr><td class="td" colspan="5">No members yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
