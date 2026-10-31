@extends('layouts.app')

@section('title', 'Free trial review')

@section('content')
    <div class="mx-auto max-w-xl">
        <h1 class="mb-1 text-2xl font-extrabold">Your free trial review</h1>
        <p class="mb-4 text-stone-600">
            {{ $campaign->product->title }}. Please be honest: say what is good and what is not. Add at least one photo.
            Your review will show a "Free trial" label so other buyers know you got it for {{ \App\Support\Money::isZero($campaign->trial_price) ? 'free' : money($campaign->trial_price) }}.
            @if ($application->review_due_at) Please write it by {{ $application->review_due_at->format('j M') }}. @endif
        </p>

        @if ($application->status === 'reviewed')
            <p class="card p-4">Thank you, your review is posted.</p>
        @elseif (! $item)
            <p class="card p-4">You can write the review once your trial order is completed (after you confirm you got it).</p>
        @else
            <form method="POST" action="{{ route('trials.review.store', $campaign) }}" enctype="multipart/form-data" class="card space-y-4 p-5">
                @csrf
                <fieldset>
                    <legend class="label">Your rating</legend>
                    <div class="flex gap-2">
                        @for ($i = 1; $i <= 5; $i++)
                            <label class="choice"><input type="radio" name="rating" value="{{ $i }}" class="size-5 accent-brand-700" @checked((int) old('rating') === $i) required> {{ $i }} ★</label>
                        @endfor
                    </div>
                    @error('rating') <p class="field-error">{{ $message }}</p> @enderror
                </fieldset>
                <div>
                    <label for="comment" class="label">Your honest review</label>
                    <textarea id="comment" name="comment" rows="5" minlength="20" maxlength="1000" required class="input">{{ old('comment') }}</textarea>
                    @error('comment') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="photos" class="label">Photos (1 to 4)</label>
                    <input id="photos" name="photos[]" type="file" multiple required accept="image/jpeg,image/png,image/webp" class="input">
                    @error('photos') <p class="field-error">{{ $message }}</p> @enderror
                    @error('photos.*') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <button class="btn btn-primary">Post my review</button>
            </form>
        @endif
    </div>
@endsection
