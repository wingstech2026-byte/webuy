@extends('seller.layout')

@section('seller')
    <h1 class="mb-1 text-2xl font-extrabold">Group buying</h1>
    <p class="mb-4 text-stone-600">Offer a lower price when enough people buy together. Each member pays their own order. If a group does not fill up in time, everyone is refunded automatically and the stock comes back. You can accept group orders once the group is complete.</p>

    @foreach ($campaigns as $c)
        @php($s = $stats[$c->id])
        <div class="card mb-2 flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
            <span><strong>{{ $c->product->title }}</strong> &middot; {{ money($c->group_price) }} (normal {{ money($c->product->price) }}) &middot; {{ $c->min_group_size }} people in {{ $c->hours }} h
                &middot; {{ $s['succeeded'] }} complete, {{ $s['failed'] }} failed, {{ $s['open'] }} open &middot; {{ $s['units_sold'] }} units, {{ money($s['revenue']) }}</span>
            <form method="POST" action="{{ route('seller.group-buys.toggle', $c) }}">@csrf<button class="btn btn-sm {{ $c->is_active ? 'btn-outline' : 'btn-primary' }}">{{ $c->is_active ? 'Switch off' : 'Switch on' }}</button></form>
        </div>
    @endforeach

    @if ($products->isEmpty())
        <p class="card p-4">Publish a product first.</p>
    @else
        <form method="POST" action="{{ route('seller.group-buys.store') }}" class="card mt-4 grid max-w-3xl gap-3 p-4 sm:grid-cols-3">
            @csrf
            <div class="sm:col-span-3"><label class="label" for="g-product">Product</label>
                <select id="g-product" name="product" class="input">@foreach ($products as $p) <option value="{{ $p->slug }}">{{ $p->title }} ({{ money($p->price) }})</option> @endforeach</select>
                @error('product') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="group_price">Group price each (Le)</label><input id="group_price" name="group_price" type="number" step="0.01" min="0.01" required class="input" value="{{ old('group_price') }}">@error('group_price') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="min_group_size">People needed</label><input id="min_group_size" name="min_group_size" type="number" min="2" required class="input" value="{{ old('min_group_size', 3) }}"></div>
            <div><label class="label" for="hours">Time to fill (hours)</label><input id="hours" name="hours" type="number" min="1" max="168" required class="input" value="{{ old('hours', 24) }}"></div>
            <div><label class="label" for="max_quantity_each">Most each person can buy</label><input id="max_quantity_each" name="max_quantity_each" type="number" min="1" required class="input" value="{{ old('max_quantity_each', 5) }}"></div>
            <div><label class="label" for="max_group_size">Largest group (optional)</label><input id="max_group_size" name="max_group_size" type="number" min="2" class="input" value="{{ old('max_group_size') }}"></div>
            <div><label class="label" for="collection_point_id">Collection point (optional)</label>
                <select id="collection_point_id" name="collection_point_id" class="input"><option value="">None</option>@foreach ($pickupPoints as $pp) <option value="{{ $pp->id }}">{{ $pp->public_name }}</option> @endforeach</select></div>
            <fieldset class="sm:col-span-3"><legend class="label">Deliver only to (optional; empty = everywhere you deliver)</legend>
                <div class="flex flex-wrap gap-2">@foreach ($districts as $d) <label class="choice text-sm"><input type="checkbox" name="eligible_districts[]" value="{{ $d }}" class="size-4 accent-brand-700"> {{ $d }}</label> @endforeach</div></fieldset>
            <div class="sm:col-span-3"><button class="btn btn-primary">Create group offer</button></div>
        </form>
    @endif
@endsection
