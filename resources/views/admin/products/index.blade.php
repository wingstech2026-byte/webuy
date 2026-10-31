@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Products</h1>

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        <input type="search" name="q" value="{{ $term }}" placeholder="Search title" class="input max-w-xs">
        <select name="status" class="input max-w-40" aria-label="Status">
            <option value="">Any status</option>
            @foreach (\App\Enums\ProductStatus::cases() as $case)
                <option value="{{ $case->value }}" @selected($status === $case)>{{ $case->label() }}</option>
            @endforeach
        </select>
        <select name="featured" class="input max-w-48" aria-label="Featured">
            <option value="">All</option>
            <option value="deal" @selected(request('featured') === 'deal')>Deals of the day</option>
            <option value="home" @selected(request('featured') === 'home')>Featured on home</option>
        </select>
        <button class="btn btn-outline btn-sm">Filter</button>
    </form>

    <div class="card divide-y divide-stone-100">
        @forelse ($items as $product)
            <div class="flex flex-wrap items-center gap-3 p-3">
                <x-product-image :product="$product" class="size-14 shrink-0 rounded-lg text-xl" />
                <div class="min-w-0 flex-1">
                    <a href="{{ route('products.show', $product) }}" class="block truncate font-semibold">{{ $product->title }}</a>
                    <p class="text-sm text-stone-600">{{ money($product->price) }} &middot; {{ $product->seller->shop_name }} &middot; {{ $product->stock }} in stock</p>
                </div>
                <span class="badge {{ $product->status->badge() }}">{{ $product->status->label() }}</span>
                <div class="flex flex-wrap gap-2">
                    @foreach (['deal' => ['is_deal', 'Deal of the day'], 'home' => ['is_featured', 'Home page']] as $slot => [$column, $label])
                        <form method="POST" action="{{ route('admin.products.feature', $product) }}">@csrf
                            <input type="hidden" name="slot" value="{{ $slot }}">
                            <button class="badge py-1.5 {{ $product->{$column} ? 'bg-amber-400 text-stone-900' : 'bg-white ring-1 ring-stone-200' }}">{{ $product->{$column} ? '★ ' : '☆ ' }}{{ $label }}</button>
                        </form>
                    @endforeach
                    <form method="POST" action="{{ route('admin.products.suspend', $product) }}">@csrf
                        <button class="badge py-1.5 bg-white text-red-700 ring-1 ring-red-200">{{ $product->status === \App\Enums\ProductStatus::Suspended ? 'Restore' : 'Suspend' }}</button>
                    </form>
                </div>
            </div>
        @empty
            <p class="p-4 text-stone-500">No products found.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $items->links() }}</div>
@endsection
