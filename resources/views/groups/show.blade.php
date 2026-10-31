@extends('layouts.app')

@section('title', 'Join the group: '.$group->campaign->product->title)

@section('content')
    @php($product = $group->campaign->product)
    <div class="mx-auto max-w-md space-y-4">
        <div class="card overflow-hidden">
            <x-product-image :product="$product" class="aspect-square w-full object-cover" />
            <div class="space-y-2 p-4">
                <h1 class="text-xl font-extrabold">👥 {{ $group->leader->first_name }}'s group: {{ $product->title }}</h1>
                <p><span class="text-2xl font-extrabold text-brand-700">{{ money($info['group_price']) }}</span>
                    <span class="text-stone-500">each (normal price {{ money($info['normal_price']) }})</span></p>
                @php($percent = min(100, (int) round($info['paid_count'] * 100 / max(1, $info['min_group_size']))))
                <div class="h-3 overflow-hidden rounded-full bg-stone-200" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full bg-brand-600" style="width: {{ $percent }}%"></div></div>
                <p class="text-sm text-stone-600">
                    {{ $info['paid_count'] }} of {{ $info['min_group_size'] }} paid
                    @if ($info['status'] === 'open') &middot; {{ $info['needed'] }} more needed &middot; ends {{ $group->expires_at->diffForHumans() }}
                    @elseif ($info['status'] === 'succeeded') &middot; complete! 🎉
                    @else &middot; this group closed. Everyone who paid is refunded. @endif
                </p>
                <p class="text-xs text-stone-500">Each member pays and gets their own order. Members only see each other's first names. If the group does not fill up in time, everyone is refunded.</p>
            </div>
        </div>

        @if ($info['status'] === 'open')
            <a class="btn btn-primary w-full text-lg" href="{{ route('app.get', ['path' => '/groups/'.$group->token]) }}">Join in the WiBuy app</a>
        @endif
        <a class="btn btn-outline w-full" href="{{ route('products.show', $product) }}">See the product</a>
    </div>
@endsection
