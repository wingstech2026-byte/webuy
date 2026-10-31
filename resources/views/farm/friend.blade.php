@extends('layouts.app')

@section('title', 'Water '.$farm->user->first_name."'s plant")

@section('content')
    <div class="mx-auto max-w-md space-y-4 text-center">
        <div class="card space-y-3 p-6">
            <p class="text-6xl" aria-hidden="true">{{ $farm->plant === 'mango' ? '🥭' : '🌾' }}</p>
            <h1 class="text-xl font-extrabold">Water {{ $farm->user->first_name }}'s {{ \App\Models\Farm::PLANTS[$farm->plant] ?? 'plant' }}</h1>
            <p class="text-stone-600">{{ $farm->stage }} &middot; {{ $farm->progress_percent }}% grown</p>
            <div class="h-3 overflow-hidden rounded-full bg-stone-200" role="progressbar" aria-valuenow="{{ $farm->progress_percent }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full bg-brand-600" style="width: {{ $farm->progress_percent }}%"></div>
            </div>
            <p class="text-sm text-stone-600">When it is fully grown, {{ $farm->user->first_name }} wins a real WiBuy prize.</p>
        </div>

        @auth
            @if (auth()->id() === $farm->user_id)
                <p class="card p-4">This is your plant. Share this page with friends so they can water it.</p>
            @else
                <form method="POST" action="{{ route('farm.water', $farm->code) }}">@csrf
                    <button class="btn btn-primary w-full text-lg">💧 Water the plant</button>
                </form>
                <p class="text-sm text-stone-600">Your own plant gets water too. Grow one in the WiBuy app (Rewards tab).</p>
            @endif
        @else
            <form method="POST" action="{{ route('farm.water', $farm->code) }}">@csrf
                <button class="btn btn-primary w-full text-lg">Log in to water the plant</button>
            </form>
        @endauth
    </div>
@endsection
