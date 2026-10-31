@extends('layouts.app')

@section('title', 'Report a problem')

@section('content')
    <div class="mx-auto max-w-xl">
        <a href="{{ route('orders.show', $order) }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; Order {{ $order->number }}</a>
        <h1 class="mb-1 text-2xl font-extrabold">Report a problem</h1>
        <p class="mb-4 text-stone-600">Tell us what went wrong with your order from {{ $order->seller->shop_name }}. Your money stays on hold until the WiBuy team decides.</p>

        <form method="POST" action="{{ route('disputes.store', $order) }}" enctype="multipart/form-data" class="card space-y-4 p-5">
            @csrf
            <fieldset>
                <legend class="label">What went wrong?</legend>
                <div class="space-y-2">
                    @foreach ($reasons as $value => $label)
                        <label class="choice"><input type="radio" name="reason" value="{{ $value }}" class="size-5 shrink-0 accent-brand-700" @checked(old('reason') === $value) required> {{ $label }}</label>
                    @endforeach
                </div>
                @error('reason') <p class="field-error">{{ $message }}</p> @enderror
            </fieldset>
            <div>
                <label for="description" class="label">Explain the problem</label>
                <textarea id="description" name="description" rows="4" maxlength="2000" required class="input">{{ old('description') }}</textarea>
                @error('description') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="photos" class="label">Photos (optional, up to 4)</label>
                <input id="photos" name="photos[]" type="file" multiple accept="image/jpeg,image/png,image/webp" class="input">
                @error('photos') <p class="field-error">{{ $message }}</p> @enderror
                @error('photos.*') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button class="btn btn-danger">Send the problem report</button>
        </form>
    </div>
@endsection
