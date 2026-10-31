@extends('layouts.app')

@section('title', 'Return items from '.$order->number)

@section('content')
    <a href="{{ route('orders.show', $order) }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; Back to the order</a>

    <form method="POST" action="{{ route('returns.store', $order) }}" enctype="multipart/form-data" class="card mx-auto max-w-xl space-y-4 p-4">
        @csrf
        <h1 class="text-xl font-extrabold">Return items</h1>
        <p class="text-sm text-stone-600">Your money stays with WiBuy. The seller has {{ config('wibuy.returns.respond_hours') }} hours to answer; if they refuse, the WiBuy team decides. You are refunded when the seller has the items back.</p>

        <fieldset class="space-y-2">
            <legend class="label">What are you sending back?</legend>
            @foreach ($order->items as $item)
                <div class="flex items-center justify-between gap-3 rounded-xl border border-stone-200 p-3">
                    <label for="qty-{{ $item->id }}" class="text-sm"><strong>{{ $item->title }}</strong><br>{{ money($item->unit_price) }} each &middot; you got {{ $item->quantity }}</label>
                    <input id="qty-{{ $item->id }}" name="items[{{ $item->id }}]" type="number" min="0" max="{{ $item->quantity }}" value="{{ old('items.'.$item->id, $order->items->count() === 1 ? $item->quantity : 0) }}" class="input w-20">
                </div>
            @endforeach
            @error('items') <p class="field-error">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="label">Why?</legend>
            @foreach ($reasons as $value => $label)
                <label class="choice"><input type="radio" name="reason" value="{{ $value }}" required class="size-5 accent-brand-700" @checked(old('reason') === $value)> {{ $label }}</label>
            @endforeach
            @error('reason') <p class="field-error">{{ $message }}</p> @enderror
        </fieldset>

        <div><label class="label" for="description">Tell the seller what is wrong</label>
            <textarea id="description" name="description" required minlength="10" maxlength="1000" rows="4" class="input">{{ old('description') }}</textarea>
            @error('description') <p class="field-error">{{ $message }}</p> @enderror</div>

        <div><label class="label" for="photos">Photos (up to 4, helps a lot)</label>
            <input id="photos" name="photos[]" type="file" accept="image/*" multiple class="input">
            @error('photos.*') <p class="field-error">{{ $message }}</p> @enderror</div>

        <p class="text-xs text-stone-500">Changed your mind? Talk to the seller first: returns here are for items that arrived damaged, wrong, not as described, incomplete or not working.</p>
        <button class="btn btn-primary w-full">Ask for a return</button>
    </form>
@endsection
