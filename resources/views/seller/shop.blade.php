@extends('seller.layout')

@section('seller')
    <h1 class="mb-4 text-2xl font-extrabold">Shop</h1>

    <div class="grid gap-6 lg:grid-cols-2">
        <form method="POST" action="{{ route('seller.shop.update') }}" enctype="multipart/form-data" class="card space-y-4 p-5">
            @csrf @method('PUT')
            <h2 class="font-bold">Shop profile</h2>
            @include('sell.fields', ['seller' => $seller])
            <button class="btn btn-primary">Save shop</button>
        </form>

        <form method="POST" action="{{ route('seller.shop.delivery') }}" class="card space-y-3 p-5">
            @csrf @method('PUT')
            <h2 class="font-bold">Delivery fees</h2>
            @unless ($seller->offers_delivery)
                <p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">You have delivery turned off, so buyers can only pick up. Turn on "I deliver" in your profile to use these.</p>
            @endunless
            <p class="text-sm text-stone-600">Enter what you charge to deliver to each district. Leave a district empty if you do not deliver there.</p>
            @php($fees = $seller->deliveryAreas->pluck('fee', 'district'))
            @error('fees.*') <p class="field-error">{{ $message }}</p> @enderror
            @foreach ($districts as $district)
                <div class="flex items-center gap-3">
                    <label for="fee-{{ $loop->index }}" class="flex-1 text-sm font-semibold">{{ $district }}</label>
                    <input id="fee-{{ $loop->index }}" name="fees[{{ $district }}]" type="number" step="0.01" min="0" inputmode="decimal"
                           class="input w-32" placeholder="—" value="{{ old('fees.'.$district, $fees[$district] ?? '') }}">
                </div>
            @endforeach
            <button class="btn btn-primary">Save delivery fees</button>
        </form>
    </div>
@endsection
