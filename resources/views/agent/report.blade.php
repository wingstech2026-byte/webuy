@extends('layouts.app')

@section('title', 'Inspection report')

@section('content')
    <div class="mx-auto max-w-xl">
        <a href="{{ route('agent.inspections.index') }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; My inspections</a>
        <h1 class="mb-1 text-2xl font-extrabold">Inspect order {{ $inspection->order->number }}</h1>
        <p class="mb-4 text-stone-600">At {{ $inspection->order->seller->shop_name }}, {{ $inspection->order->seller->pickup_address }}. Check the quantity, the quality and that it matches the order.</p>
        <ul class="card mb-4 list-inside list-disc p-4 text-sm">
            @foreach ($inspection->order->items as $item) <li>{{ $item->quantity }} &times; {{ $item->title }}</li> @endforeach
        </ul>

        @if ($inspection->status !== 'assigned')
            <p class="card p-4">Report sent: <strong>{{ $inspection->status }}</strong>. {{ $inspection->report }}</p>
        @else
            <form method="POST" action="{{ route('agent.inspections.update', $inspection) }}" enctype="multipart/form-data" class="card space-y-4 p-5">
                @csrf
                <fieldset>
                    <legend class="label">Result</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="choice justify-center font-bold"><input type="radio" name="passed" value="1" required class="size-5 accent-brand-700"> ✅ Pass</label>
                        <label class="choice justify-center font-bold"><input type="radio" name="passed" value="0" class="size-5 accent-brand-700"> ❌ Fail</label>
                    </div>
                    @error('passed') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>
                <div>
                    <label for="report" class="label">What you checked and found</label>
                    <textarea id="report" name="report" rows="5" required minlength="10" maxlength="2000" class="input">{{ old('report') }}</textarea>
                    @error('report') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="photos" class="label">Photos of the goods (1 to 8)</label>
                    <input id="photos" name="photos[]" type="file" multiple required accept="image/jpeg,image/png,image/webp" capture="environment" class="input">
                    @error('photos') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <button class="btn btn-primary">Send report</button>
            </form>
        @endif
    </div>
@endsection
