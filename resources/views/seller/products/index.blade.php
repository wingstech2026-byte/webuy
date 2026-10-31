@extends('seller.layout')

@section('seller')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-extrabold">Products</h1>
        <a href="{{ route('seller.products.create') }}" class="btn btn-primary btn-sm">Add product</a>
        <a href="{{ route('seller.products.import') }}" class="btn btn-outline btn-sm">Add many (spreadsheet)</a>
    </div>

    <form method="GET" class="mb-4 flex gap-2">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Search my products" class="input max-w-xs">
        <button class="btn btn-outline btn-sm">Search</button>
    </form>

    @if ($items->isEmpty())
        <x-empty title="No products yet" action="Add your first product" :href="route('seller.products.create')">
            Add a title, price, stock and a few photos. It takes two minutes.
        </x-empty>
    @else
        <div class="card divide-y divide-stone-100">
            @foreach ($items as $product)
                <div class="flex items-center gap-3 p-3">
                    <x-product-image :product="$product" class="size-14 shrink-0 rounded-lg text-xl" />
                    <div class="min-w-0 flex-1">
                        <a href="{{ route('seller.products.edit', $product) }}" class="block truncate font-semibold">{{ $product->title }}</a>
                        <p class="text-sm text-stone-600">{{ money($product->price) }} &middot; {{ $product->stock }} in stock &middot; {{ $product->sold_count }} sold</p>
                    </div>
                    <span class="badge {{ $product->status->badge() }}">{{ $product->status->label() }}</span>
                </div>
            @endforeach
        </div>
        <div class="mt-4">{{ $items->links() }}</div>
    @endif
@endsection
