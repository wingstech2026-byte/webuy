@extends('layouts.app')

@section('title', 'Wishlist')

@section('content')
    <h1 class="mb-4 text-2xl font-extrabold">Your wishlist</h1>

    @if ($products->isEmpty())
        <x-empty title="Nothing saved yet" action="Browse products" :href="route('search')">
            Tap "♡ Save" on a product to keep it here for later.
        </x-empty>
    @else
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($products as $product)
                <div>
                    <x-product-card :product="$product" />
                    <form method="POST" action="{{ route('wishlist.toggle', $product) }}" class="mt-1">
                        @csrf
                        <button class="w-full text-center text-sm font-semibold text-red-700 underline">Remove</button>
                    </form>
                </div>
            @endforeach
        </div>
        <div class="mt-6">{{ $products->links() }}</div>
    @endif
@endsection
