@extends('layouts.app')

@section('title', 'Problem with order '.$dispute->order->number)

@php
    $viewer = auth()->user();
    $isSeller = $viewer->isSeller() && $viewer->seller->id === $dispute->seller_id;
    $photosBy = fn ($userId) => $dispute->photos->where('user_id', $userId);
@endphp

@section('content')
    <div class="mx-auto max-w-2xl space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-2xl font-extrabold">Problem with order {{ $dispute->order->number }}</h1>
                <p class="text-stone-600">{{ $dispute->buyer->first_name }} &rarr; {{ $dispute->seller->shop_name }} &middot; {{ money($dispute->order->total) }}</p>
            </div>
            <span class="badge {{ $dispute->status->badge() }} text-sm">{{ $dispute->status->label() }}</span>
        </div>

        <div class="card p-4">
            <p class="text-sm font-bold text-stone-500">The buyer says ({{ $dispute->created_at->format('j M, g:ia') }})</p>
            <p class="font-bold">{{ $dispute->reason }}</p>
            <p class="mt-1 whitespace-pre-line">{{ $dispute->description }}</p>
            @include('disputes.photos', ['photos' => $photosBy($dispute->buyer_id)])
        </div>

        <div class="card p-4">
            <p class="text-sm font-bold text-stone-500">The seller says</p>
            @if ($dispute->seller_response)
                <p class="mt-1 whitespace-pre-line">{{ $dispute->seller_response }}</p>
                @include('disputes.photos', ['photos' => $photosBy($dispute->seller->user_id)])
            @else
                <p class="text-stone-500">No reply yet.</p>
            @endif

            @if ($isSeller && $dispute->isOpen())
                <form method="POST" action="{{ route('seller.disputes.respond', $dispute) }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                    @csrf
                    <label for="response" class="label">{{ $dispute->seller_response ? 'Update your reply' : 'Your reply' }}</label>
                    <textarea id="response" name="response" rows="4" maxlength="2000" required class="input">{{ old('response', $dispute->seller_response) }}</textarea>
                    @error('response') <p class="field-error">{{ $message }}</p> @enderror
                    <input name="photos[]" type="file" multiple accept="image/jpeg,image/png,image/webp" class="input" aria-label="Photos (optional)">
                    @error('photos.*') <p class="field-error">{{ $message }}</p> @enderror
                    <button class="btn btn-primary">Send reply</button>
                </form>
            @endif
        </div>

        @if (! $dispute->isOpen())
            <div class="card border-brand-200 bg-brand-50 p-4">
                <p class="font-bold">{{ $dispute->status->label() }}</p>
                @if ($dispute->resolution_note) <p>{{ $dispute->resolution_note }}</p> @endif
                <p class="text-xs text-stone-500">{{ $dispute->resolved_at?->format('j M Y, g:ia') }}</p>
            </div>
        @elseif ($viewer->is_admin)
            <form method="POST" action="{{ route('admin.disputes.resolve', $dispute) }}" class="card space-y-3 border-red-200 p-4"
                  onsubmit="return confirm('Save this decision? Money will move straight away.')">
                @csrf
                <p class="font-bold">Admin decision</p>
                <label class="choice"><input type="radio" name="decision" value="buyer" required class="size-5 accent-brand-700"> Refund the buyer {{ money($dispute->order->total) }}</label>
                <label class="choice"><input type="radio" name="decision" value="seller" required class="size-5 accent-brand-700"> Release the money to the seller</label>
                @error('decision') <p class="field-error">{{ $message }}</p> @enderror
                <textarea name="note" rows="2" maxlength="1000" class="input" placeholder="Note shown to both sides (optional)"></textarea>
                <button class="btn btn-primary">Save decision</button>
            </form>
        @else
            <p class="text-center text-sm text-stone-500">The WiBuy team will look at both sides and decide. We will text you.</p>
        @endif

        <a href="{{ $isSeller ? route('seller.orders.show', $dispute->order) : ($viewer->is_admin ? route('admin.orders.show', $dispute->order) : route('orders.show', $dispute->order)) }}" class="btn btn-outline">See the order</a>
    </div>
@endsection
