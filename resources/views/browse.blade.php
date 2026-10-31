@extends('layouts.app')

@section('title', $category?->name ?? (filled($filters['q'] ?? null) ? 'Search: '.$filters['q'] : 'All products'))

@section('content')
    <div class="mb-4">
        @if ($category)
            @if ($category->parent)
                <a href="{{ route('categories.show', $category->parent) }}" class="text-sm font-semibold text-brand-700">&larr; {{ $category->parent->name }}</a>
            @endif
            <h1 class="text-2xl font-extrabold">{{ $category->emoji }} {{ $category->name }}</h1>
            @if ($category->children->isNotEmpty())
                <div class="-mx-4 mt-2 flex gap-2 overflow-x-auto px-4 pb-1">
                    @foreach ($category->children as $child)
                        <a href="{{ route('categories.show', $child) }}" class="badge whitespace-nowrap bg-white py-2 text-sm ring-1 ring-stone-200 hover:bg-brand-50">{{ $child->name }}</a>
                    @endforeach
                </div>
            @endif
        @else
            <h1 class="text-2xl font-extrabold">{{ filled($filters['q'] ?? null) ? 'Results for "'.$filters['q'].'"' : 'All products' }}</h1>
        @endif
        <p class="text-sm text-stone-500">{{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }}</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        {{-- Filters: a plain GET form, works without JavaScript --}}
        <details class="card self-start p-4 lg:block" @if (request()->hasAny(['min_price', 'max_price', 'district', 'verified', 'sort'])) open @endif>
            <summary class="cursor-pointer font-bold">Filter and sort</summary>
            <form method="GET" action="{{ $category ? route('categories.show', $category) : route('search') }}" class="mt-3 space-y-3">
                @if (filled($filters['q'] ?? null))
                    <input type="hidden" name="q" value="{{ $filters['q'] }}">
                @endif

                @unless ($category)
                    <div>
                        <label for="category" class="label">Category</label>
                        <select id="category" name="category" class="input">
                            <option value="">All categories</option>
                            @foreach ($categories as $parent)
                                <option value="{{ $parent->slug }}" @selected(($filters['category'] ?? '') === $parent->slug)>{{ $parent->name }}</option>
                                @foreach ($parent->children as $child)
                                    <option value="{{ $child->slug }}" @selected(($filters['category'] ?? '') === $child->slug)>&nbsp;&nbsp;{{ $child->name }}</option>
                                @endforeach
                            @endforeach
                        </select>
                    </div>
                @endunless

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label for="min_price" class="label">Min price (Le)</label>
                        <input id="min_price" name="min_price" type="number" min="0" step="0.01" inputmode="decimal" value="{{ $filters['min_price'] ?? '' }}" class="input">
                    </div>
                    <div>
                        <label for="max_price" class="label">Max price (Le)</label>
                        <input id="max_price" name="max_price" type="number" min="0" step="0.01" inputmode="decimal" value="{{ $filters['max_price'] ?? '' }}" class="input">
                    </div>
                </div>

                <div>
                    <label for="district" class="label">Seller location</label>
                    <select id="district" name="district" class="input">
                        <option value="">Anywhere</option>
                        @foreach ($districts as $d)
                            <option value="{{ $d }}" @selected(($filters['district'] ?? '') === $d)>{{ $d }}</option>
                        @endforeach
                    </select>
                </div>

                <label class="choice">
                    <input type="checkbox" name="verified" value="1" class="size-5 shrink-0 accent-brand-700" @checked(! empty($filters['verified']))>
                    <span class="font-semibold">Verified sellers only</span>
                </label>

                <div>
                    <label for="sort" class="label">Sort by</label>
                    <select id="sort" name="sort" class="input">
                        @foreach ($sorts as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['sort'] ?? 'newest') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <button class="btn btn-primary">Show results</button>
                <a href="{{ $category ? route('categories.show', $category) : route('search', ['q' => $filters['q'] ?? null]) }}" class="block text-center text-sm font-semibold text-brand-700">Clear filters</a>
            </form>
        </details>

        <div>
            @if ($products->isEmpty())
                <x-empty title="Nothing found" action="See all products" :href="route('search')">
                    Try a different word, or remove some filters.
                </x-empty>
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                    @foreach ($products as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
                <div class="mt-6">{{ $products->links() }}</div>
            @endif
        </div>
    </div>
@endsection
