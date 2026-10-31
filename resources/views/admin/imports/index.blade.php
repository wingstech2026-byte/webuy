@extends('admin.layout')

@section('admin')
    <h1 class="mb-1 text-2xl font-extrabold">WiBuy Global</h1>
    <p class="mb-4 text-stone-600">Products sourced from China. Prices are landed prices per unit (goods, shipping and duties). Buyers pay a deposit, then the balance on arrival.
        @unless ($enabled) <strong class="text-red-700">Switched off for buyers: set WIBUY_GLOBAL_ENABLED=true in .env.</strong> @endunless</p>

    <h2 class="mb-2 text-lg font-extrabold">Import orders</h2>
    @forelse ($orders as $order)
        <div class="card mb-2 p-3 text-sm">
            <div class="flex flex-wrap justify-between gap-2">
                <span><strong>{{ $order->number }}</strong> &middot; {{ $order->user->name }} &middot; {{ $order->quantity }} &times; {{ $order->product->title }} by {{ $order->shipping }} &middot; {{ money($order->total) }}
                    (deposit {{ $order->deposit_paid_at ? 'paid' : 'unpaid' }}, balance {{ $order->balance_paid_at ? 'paid' : 'unpaid' }}) &middot; ETA {{ $order->estimated_arrival?->format('j M') }}</span>
                <span class="badge bg-stone-100">{{ $order->status_label }}</span>
            </div>
            @if ($order->deposit_paid_at && $order->status !== 'completed')
                <form method="POST" action="{{ route('admin.global.orders.advance', $order) }}" class="mt-2 flex gap-2">@csrf
                    <select name="status" class="input max-w-64" aria-label="Next step">
                        @foreach ($steps as $step) <option value="{{ $step }}" @selected($step === $order->status)>{{ \App\Models\ImportOrder::STEPS[$step] }}</option> @endforeach
                    </select>
                    <button class="btn btn-primary btn-sm">Update and SMS buyer</button>
                </form>
            @endif
        </div>
    @empty
        <p class="mb-4 text-stone-500">No import orders yet.</p>
    @endforelse
    {{ $orders->links() }}

    <h2 class="mb-2 mt-6 text-lg font-extrabold">Import products</h2>
    @foreach ($products as $p)
        <div class="card mb-2 flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
            <span><strong>{{ $p->title }}</strong> ({{ $p->origin }}, min {{ $p->min_quantity }} {{ $p->unit }}) &middot;
                @if ($p->sea_price) sea {{ money($p->sea_price) }} / {{ $p->sea_days }} days @endif
                @if ($p->air_price) &middot; air {{ money($p->air_price) }} / {{ $p->air_days }} days @endif
                &middot; deposit {{ $p->deposit_percent }}%</span>
            <form method="POST" action="{{ route('admin.global.products.toggle', $p) }}">@csrf<button class="btn btn-sm {{ $p->is_active ? 'btn-outline' : 'btn-primary' }}">{{ $p->is_active ? 'Hide' : 'Show' }}</button></form>
        </div>
    @endforeach
    <form method="POST" action="{{ route('admin.global.products.store') }}" class="card mt-3 grid max-w-3xl gap-3 p-4 sm:grid-cols-4">
        @csrf
        <div class="sm:col-span-4"><label class="label" for="g-title">Product</label><input id="g-title" name="title" required maxlength="160" class="input" value="{{ old('title') }}"></div>
        <div class="sm:col-span-4"><label class="label" for="g-desc">Description</label><textarea id="g-desc" name="description" rows="2" class="input">{{ old('description') }}</textarea></div>
        <div><label class="label" for="g-origin">From</label><input id="g-origin" name="origin" required class="input" value="{{ old('origin', 'China') }}"></div>
        <div><label class="label" for="g-unit">Unit</label><input id="g-unit" name="unit" required class="input" value="{{ old('unit', 'piece') }}"></div>
        <div><label class="label" for="g-min">Minimum</label><input id="g-min" name="min_quantity" type="number" min="1" required class="input" value="{{ old('min_quantity', 1) }}"></div>
        <div><label class="label" for="g-dep">Deposit %</label><input id="g-dep" name="deposit_percent" type="number" min="1" max="100" required class="input" value="{{ old('deposit_percent', 30) }}"></div>
        <div><label class="label" for="g-sea">Sea: landed Le/unit</label><input id="g-sea" name="sea_price" type="number" step="0.01" class="input" value="{{ old('sea_price') }}"></div>
        <div><label class="label" for="g-sead">Sea days</label><input id="g-sead" name="sea_days" type="number" class="input" value="{{ old('sea_days', 45) }}"></div>
        <div><label class="label" for="g-air">Air: landed Le/unit</label><input id="g-air" name="air_price" type="number" step="0.01" class="input" value="{{ old('air_price') }}"></div>
        <div><label class="label" for="g-aird">Air days</label><input id="g-aird" name="air_days" type="number" class="input" value="{{ old('air_days', 10) }}"></div>
        <div class="sm:col-span-4">@foreach (['title', 'sea_price', 'sea_days', 'air_days'] as $f) @error($f) <p class="field-error">{{ $message }}</p> @enderror @endforeach <button class="btn btn-primary">Add import product</button></div>
    </form>
@endsection
