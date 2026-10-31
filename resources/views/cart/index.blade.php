@extends('layouts.app')

@section('title', 'Cart')

@section('content')
    <h1 class="mb-4 text-2xl font-extrabold">Your cart</h1>

    @if ($groups->isEmpty())
        <x-empty title="Your cart is empty" action="Start shopping" :href="route('home')">
            Find something you like and press "Add to cart".
        </x-empty>
    @else
        <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
            <div class="space-y-4">
                @foreach ($groups as $group)
                    <section class="card overflow-hidden">
                        <header class="flex items-center justify-between gap-2 border-b border-stone-100 px-4 py-3">
                            <a href="{{ route('shops.show', $group['seller']) }}" class="font-bold">
                                {{ $group['seller']->shop_name }}
                                @if ($group['seller']->is_verified) <span class="text-brand-700">✓</span> @endif
                            </a>
                            <span class="text-xs text-stone-500">{{ $group['seller']->district }} &middot; one order</span>
                        </header>
                        <ul class="divide-y divide-stone-100">
                            @foreach ($group['lines'] as $line)
                                @php($product = $line['product'])
                                <li class="flex gap-3 p-4">
                                    <a href="{{ route('products.show', $product) }}"><x-product-image :product="$product" class="size-20 shrink-0 rounded-lg" /></a>
                                    <div class="min-w-0 flex-1">
                                        <a href="{{ route('products.show', $product) }}" class="line-clamp-2 font-semibold">{{ $product->title }}</a>
                                        @if ($line['variant']) <p class="text-sm font-semibold text-brand-800">{{ $line['variant']->name }}</p> @endif
                                        <p class="text-sm text-stone-600">{{ money($line['unit_price']) }} per {{ $product->unit }}
                                            @if ($line['unit_price'] !== (string) $product->price) <span class="badge bg-amber-100 text-amber-800">wholesale price</span> @endif
                                        </p>
                                        @if ($line['problem'])
                                            <p class="field-error">{{ $line['problem'] }}</p>
                                        @endif
                                        <div class="mt-2 flex flex-wrap items-center gap-2">
                                            <form method="POST" action="{{ route('cart.update', $line['item']) }}" class="flex items-center gap-2">
                                                @csrf @method('PATCH')
                                                <label class="sr-only" for="qty-{{ $line['item']->id }}">Quantity</label>
                                                <input id="qty-{{ $line['item']->id }}" type="number" name="quantity" min="0" inputmode="numeric" value="{{ $line['quantity'] }}" class="input min-h-10 w-24 py-1">
                                                <button class="btn btn-outline btn-sm">Update</button>
                                            </form>
                                            <form method="POST" action="{{ route('cart.remove', $line['item']) }}">
                                                @csrf @method('DELETE')
                                                <button class="text-sm font-semibold text-red-700 underline">Remove</button>
                                            </form>
                                        </div>
                                    </div>
                                    <p class="whitespace-nowrap font-bold">{{ money($line['line_total']) }}</p>
                                </li>
                            @endforeach
                        </ul>
                        <footer class="flex justify-between border-t border-stone-100 bg-stone-50 px-4 py-2 text-sm">
                            <span>Items from this seller</span><strong>{{ money($group['subtotal']) }}</strong>
                        </footer>
                    </section>
                @endforeach
            </div>

            <aside class="card h-fit space-y-3 p-4">
                <div class="flex justify-between text-lg"><span>Items total</span><strong>{{ money($total) }}</strong></div>
                <p class="text-sm text-stone-500">Delivery fees are added at checkout. Each seller sends their part as a separate order.</p>
                @if ($hasProblems)
                    <p class="field-error">Please fix the items marked in red first.</p>
                    <button class="btn btn-primary" disabled>Checkout</button>
                @else
                    <a href="{{ route('checkout.show') }}" class="btn btn-primary">Checkout</a>
                @endif
            </aside>
        </div>
    @endif
@endsection
