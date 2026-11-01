@extends('layouts.app')

@section('title', 'Sell on '.config('wibuy.name'))

@php($editable = ! $seller || $seller->status === \App\Enums\SellerStatus::Rejected)

@section('content')
    <div class="mx-auto max-w-xl">
        <h1 class="mb-1 text-2xl font-extrabold">Sell on {{ config('wibuy.name') }}</h1>

        @if ($seller)
            <div class="card mb-4 p-4">
                <p class="flex items-center gap-2 font-bold">{{ $seller->shop_name }} <span class="badge {{ $seller->status->badge() }}">{{ $seller->status->label() }}</span></p>
                @if ($seller->status === \App\Enums\SellerStatus::Pending)
                    <p class="mt-1 text-stone-600">We are checking your application. We will text you when it is approved.</p>
                @elseif ($seller->status === \App\Enums\SellerStatus::Approved)
                    <a href="{{ route('seller.dashboard') }}" class="btn btn-primary mt-3">Go to your seller dashboard</a>
                @elseif ($seller->status === \App\Enums\SellerStatus::Rejected)
                    <p class="mt-1 text-stone-600">Your application was not approved{{ $seller->rejection_reason ? ': '.$seller->rejection_reason : '.' }} You can fix it and send it again below.</p>
                @else
                    <p class="mt-1 text-stone-600">Your shop is suspended. Please contact the WiBuy team.</p>
                @endif
            </div>
        @else
            <p class="mb-4 text-stone-600">Open a shop for free. Buyers pay {{ config('wibuy.name') }} by mobile money, you deliver, and we pay you once the buyer confirms. {{ config('wibuy.name') }} keeps a small commission per order.</p>
        @endif

        {{-- Applied but the ID is missing (or needs a clearer photo): send it on its own. --}}
        @if ($seller && in_array($seller->status, [\App\Enums\SellerStatus::Pending, \App\Enums\SellerStatus::Suspended], true))
            <form method="POST" action="{{ route('sell.identity') }}" enctype="multipart/form-data" class="card mb-4 space-y-4 p-5">
                @csrf
                @if (! $seller->hasOwnerIdentity())
                    <p class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">We need your national ID card before we can approve your shop.</p>
                @endif
                @include('sell._identity', ['onFile' => $seller->hasOwnerIdentity()])
                <button class="btn btn-primary">{{ $seller->hasOwnerIdentity() ? 'Send a new ID photo' : 'Send my ID card' }}</button>
            </form>
        @endif

        @if ($editable)
            <form method="POST" action="{{ route('sell.store') }}" enctype="multipart/form-data" class="card space-y-4 p-5">
                @csrf
                @include('sell.fields', ['seller' => $seller])
                @include('sell._identity', ['onFile' => $seller?->hasOwnerIdentity()])
                <button class="btn btn-primary">{{ $seller ? 'Send my application again' : 'Send my application' }}</button>
            </form>
        @endif
    </div>
@endsection
