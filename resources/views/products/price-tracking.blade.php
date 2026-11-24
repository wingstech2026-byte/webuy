<section class="my-4 space-y-2" aria-label="Price tracking">
    @foreach ($sellerBadges as $badge)
        <span class="badge bg-brand-100 text-brand-800">{{ $badge }}</span>
    @endforeach
    @if ($priceHistory->isNotEmpty())
        <details>
            <summary class="cursor-pointer font-semibold">Base price history (90 days)</summary>
            <table class="mt-2 w-full text-sm">
                <thead><tr><th scope="col" class="text-left">Date</th><th scope="col" class="text-right">Base price</th></tr></thead>
                <tbody>
                    @foreach ($priceHistory->reverse() as $point)
                        <tr>
                            <td><time datetime="{{ $point['recorded_at'] }}">{{ \Illuminate\Support\Carbon::parse($point['recorded_at'])->format('j M Y, H:i') }} UTC</time></td>
                            <td class="text-right">{{ money($point['price']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endif
    @if ($product->isListed())
        @auth
            <form method="POST" action="{{ route('products.price-alert', $product) }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <label class="text-sm font-semibold">
                    Notify me below (Le)
                    <input class="input block w-36" name="target" type="number" min="0.01" max="999999999999.99" step="0.01" required value="{{ old('target', $priceTarget) }}">
                </label>
                <button class="btn btn-sm btn-outline">Save price alert</button>
            </form>
            @error('target') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            @if ($priceTarget !== null)
                <form method="POST" action="{{ route('products.price-alert', $product) }}">
                    @csrf
                    <input type="hidden" name="target" value="">
                    <button class="btn btn-sm btn-outline">Remove price alert</button>
                </form>
            @endif
        @else
            <a class="btn btn-sm btn-outline" href="{{ route('login') }}">Log in to set a price alert</a>
        @endauth
    @endif
    @if ($product->isListed() && ($canSetStockAlert || $stockAlert))
        @auth
            <form method="POST" action="{{ route('products.stock-alert', $product) }}">
                @csrf
                <input type="hidden" name="subscribed" value="{{ $stockAlert ? '0' : '1' }}">
                <button class="btn btn-sm btn-outline">{{ $stockAlert ? 'Remove stock alert' : 'Notify me when back in stock' }}</button>
            </form>
            @error('subscribed') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
        @else
            <a class="btn btn-sm btn-outline" href="{{ route('login') }}">Log in for a stock alert</a>
        @endauth
    @endif
</section>
