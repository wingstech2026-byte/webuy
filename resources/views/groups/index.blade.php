@extends('layouts.app')

@section('title', 'Buy together')

@section('content')
    <h1 class="mb-1 text-2xl font-extrabold">👥 Buy together, pay less</h1>
    <p class="mb-4 text-stone-600">Group prices when enough people buy. Each person pays for their own order. If the group does not fill up in time, everyone gets their money back automatically.</p>

    @if ($campaigns->isEmpty())
        <p class="card p-4">No group offers right now. Check again soon.</p>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($campaigns as $c)
                <a href="{{ route('products.show', $c->product) }}#buy-together" class="card overflow-hidden">
                    <x-product-image :product="$c->product" class="aspect-square w-full object-cover" />
                    <div class="space-y-1 p-3 text-sm">
                        <p class="line-clamp-2 font-semibold">{{ $c->product->title }}</p>
                        <p><span class="text-lg font-extrabold text-brand-700">{{ money($c->group_price) }}</span> <span class="text-stone-500 line-through">{{ money($c->product->price) }}</span></p>
                        <p class="text-stone-600">with {{ $c->min_group_size }} people &middot; {{ $c->hours }} h</p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
@endsection
