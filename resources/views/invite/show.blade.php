@extends('layouts.app')

@section('title', 'Invite friends')

@section('content')
    <div class="mx-auto max-w-xl space-y-4">
        <h1 class="text-2xl font-extrabold">🎁 Invite friends</h1>
        <div class="card space-y-2 p-5">
            <p>Share your link. When a friend joins, you get <strong>{{ $invite['points_join'] }} WiBuy Points</strong>.</p>
            <p>When their first order arrives, you <strong>both</strong> get a <strong>{{ money($invite['coupon']) }} coupon</strong>, and you get <strong>{{ $invite['points_order'] }} more points</strong>.</p>
            <p class="text-sm text-stone-500">Coupons and points lower the price of orders; they are never cash.</p>
        </div>
        <div class="card space-y-2 p-5">
            <label for="invite-link" class="label">Your link</label>
            <input id="invite-link" readonly class="input" value="{{ $invite['link'] }}" onclick="this.select()">
            <a class="btn btn-primary" target="_blank" rel="noopener" href="https://wa.me/?text={{ urlencode('Join me on WiBuy, Salone\'s marketplace: '.$invite['link']) }}">Share on WhatsApp</a>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div class="card p-4 text-center"><p class="text-3xl font-extrabold">{{ $invite['joined'] }}</p><p class="text-sm text-stone-600">friends joined</p></div>
            <div class="card p-4 text-center"><p class="text-3xl font-extrabold text-brand-700">{{ $invite['ordered'] }}</p><p class="text-sm text-stone-600">got their first order</p></div>
        </div>
    </div>
@endsection
