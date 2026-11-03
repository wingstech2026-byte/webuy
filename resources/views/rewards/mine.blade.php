@extends('layouts.app')

@section('title', 'My rewards')

@section('content')
    @php
        $couponRow = function ($coupon, $note = null) {
            return '<div class="flex flex-wrap items-center justify-between gap-2 border-b border-stone-100 py-2 text-sm last:border-0"><span><strong>'.e($coupon->title).'</strong> &middot; '.e($coupon->description).($coupon->app_only ? ' &middot; in the app' : '').'</span><span class="text-stone-500">'.e($note ?? '').'</span></div>';
        };
    @endphp
    <div class="mx-auto max-w-2xl space-y-4">
        <h1 class="text-2xl font-extrabold">🎁 My rewards</h1>
        <p class="text-sm text-stone-600">Points and coupons lower the price of your orders. They are never cash.</p>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><p class="text-sm text-stone-500">WiBuy Points</p><p class="text-3xl font-extrabold text-brand-700">{{ number_format($points) }}</p></div>
            <div class="card p-4"><p class="text-sm text-stone-500">Coupons to use</p><p class="text-3xl font-extrabold">{{ $usable->count() }}</p></div>
            <div class="card p-4"><p class="text-sm text-stone-500">Saved with coupons</p><p class="text-3xl font-extrabold">{{ money($saved) }}</p></div>
        </div>

        <form method="POST" action="{{ route('rewards.exchange') }}" class="card flex flex-wrap items-center justify-between gap-2 p-4">
            @csrf
            <p class="text-sm">{{ number_format($points_per_coupon) }} points = a {{ money($coupon_from_points) }} coupon.</p>
            <button class="btn btn-primary btn-sm" @disabled(! $can_exchange)>{{ $can_exchange ? 'Change points for a coupon' : 'Not enough points yet' }}</button>
        </form>

        @if ($expiring_soon->isNotEmpty())
            <section class="card border-amber-300 bg-amber-50 p-4">
                <h2 class="font-bold text-amber-900">⏰ Ends soon: use them</h2>
                @foreach ($expiring_soon as $coupon) {!! $couponRow($coupon, 'ends '.$coupon->expires_at->diffForHumans()) !!} @endforeach
            </section>
        @endif

        <section class="card p-4">
            <h2 class="mb-1 font-bold">Coupons you can use</h2>
            @forelse ($usable as $coupon) {!! $couponRow($coupon, $coupon->expires_at ? 'until '.$coupon->expires_at->format('j M') : 'no end date') !!} @empty <p class="text-sm text-stone-500">None right now. Check in daily, spin the wheel or invite friends to get some.</p> @endforelse
        </section>

        @if ($locked->isNotEmpty())
            <section class="card p-4">
                <h2 class="mb-1 font-bold">🔒 Not unlocked yet</h2>
                @foreach ($locked as $coupon) {!! $couponRow($coupon, $coupon->unlock_hint) !!} @endforeach
            </section>
        @endif

        <section class="card p-4">
            <h2 class="mb-1 font-bold">Points history</h2>
            @forelse ($history as $row)
                <div class="flex justify-between border-b border-stone-100 py-1 text-sm last:border-0">
                    <span>{{ $row->reason }} <span class="text-stone-400">&middot; {{ $row->created_at?->format('j M') }}</span></span>
                    <strong class="{{ $row->points >= 0 ? 'text-brand-700' : 'text-red-700' }}">{{ $row->points >= 0 ? '+' : '' }}{{ $row->points }}</strong>
                </div>
            @empty
                <p class="text-sm text-stone-500">No points yet.</p>
            @endforelse
        </section>

        @if ($used->isNotEmpty() || $expired->isNotEmpty())
            <details class="card p-4">
                <summary class="cursor-pointer font-bold">Used and ended coupons</summary>
                @foreach ($used as $coupon) {!! $couponRow($coupon, 'used'.($coupon->used_at ? ' '.$coupon->used_at->format('j M') : '')) !!} @endforeach
                @foreach ($expired as $coupon) {!! $couponRow($coupon, 'ended') !!} @endforeach
            </details>
        @endif
    </div>
@endsection
