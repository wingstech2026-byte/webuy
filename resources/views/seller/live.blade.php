@extends('seller.layout')

@section('seller')
    <h1 class="mb-1 text-2xl font-extrabold">🔴 Live selling</h1>
    <p class="mb-5 text-stone-600">Start a live video on YouTube or Facebook from your phone, paste its link here and pick the products you will show. When you go live, people who bought from you or saved your products are told. Pin the product you are showing: viewers get a Buy button.</p>

    @foreach ($shows as $show)
        <div class="card mb-3 space-y-2 p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p><strong>{{ $show->title }}</strong> &middot; <span class="{{ $show->isLive() ? 'font-bold text-red-600' : 'text-stone-500' }}">{{ $show->isLive() ? '🔴 LIVE' : ucfirst($show->status) }}</span> &middot; {{ $show->comments_count }} comment(s)</p>
                <a href="{{ route('live.show', $show) }}" target="_blank" class="text-sm font-semibold text-brand-700">Open the watch page</a>
            </div>
            @if ($show->status === 'scheduled')
                <form method="POST" action="{{ route('seller.live.act', [$show, 'start']) }}">@csrf<button class="btn btn-primary btn-sm">Go live now</button></form>
            @elseif ($show->isLive())
                <form method="POST" action="{{ route('seller.live.act', [$show, 'pin']) }}" class="flex flex-wrap gap-2">
                    @csrf
                    <select name="product" class="input max-w-xs" aria-label="Product to pin">@foreach ($show->products as $p) <option value="{{ $p->id }}" @selected($show->pinned_product_id === $p->id)>{{ $p->title }}</option> @endforeach</select>
                    <button class="btn btn-outline btn-sm">📌 Pin</button>
                </form>
                <form method="POST" action="{{ route('seller.live.act', [$show, 'end']) }}" onsubmit="return confirm('End the show?')">@csrf<button class="btn btn-outline btn-sm">End the show</button></form>
            @endif
        </div>
    @endforeach

    <form method="POST" action="{{ route('seller.live.store') }}" class="card space-y-3 p-4">
        @csrf
        <h2 class="font-bold">New show</h2>
        <div><label for="live-title" class="label">Title</label><input id="live-title" name="title" required maxlength="100" class="input" value="{{ old('title') }}" placeholder="New phones just arrived"></div>
        <div><label for="live-url" class="label">Link of your YouTube or Facebook live video</label><input id="live-url" name="stream_url" type="url" required class="input" value="{{ old('stream_url') }}" placeholder="https://youtube.com/live/..."></div>
        <div><label for="live-start" class="label">Starts (optional)</label><input id="live-start" name="starts_at" type="datetime-local" class="input" value="{{ old('starts_at') }}"></div>
        <fieldset><legend class="label">Products you will show</legend>
            <div class="grid gap-1 sm:grid-cols-2">@foreach ($products as $p) <label class="choice text-sm"><input type="checkbox" name="products[]" value="{{ $p->id }}" class="accent-brand-700" @checked(in_array($p->id, (array) old('products', [])))> {{ $p->title }}</label> @endforeach</div>
        </fieldset>
        <button class="btn btn-primary">Save the show</button>
    </form>
@endsection
