@extends('admin.layout')

@section('admin')
    <h1 class="mb-3 text-2xl font-extrabold">Spin wheel</h1>
    @include('admin.rewards._nav')
    <p class="mb-4 text-stone-600">Chance of each prize = its weight &divide; all active weights. People see these odds in the app. {{ $spinsToday }} spins today. Spins per day are set in <a class="font-semibold text-brand-700" href="{{ route('admin.rewards.settings') }}">Rewards and limits</a>.</p>

    @foreach ($prizes as $prize)
        <form method="POST" action="{{ route('admin.rewards.spin.update', $prize) }}" class="card mb-2 grid gap-2 p-3 sm:grid-cols-8 sm:items-end">
            @csrf @method('PUT')
            @include('admin.rewards._prize-fields', ['prize' => $prize])
            <div class="flex gap-2 sm:col-span-8">
                <span class="badge bg-stone-100">{{ $prize->is_active ? round($prize->weight * 100 / $totalWeight, 1).'% chance' : 'off' }}</span>
                <button class="btn btn-primary btn-sm">Save</button>
                <button form="delete-{{ $prize->id }}" class="btn btn-danger btn-sm">Remove</button>
            </div>
        </form>
        <form id="delete-{{ $prize->id }}" method="POST" action="{{ route('admin.rewards.spin.destroy', $prize) }}" onsubmit="return confirm('Remove this prize?')">@csrf @method('DELETE')</form>
    @endforeach

    <h2 class="mb-2 mt-6 text-lg font-extrabold">Add a prize</h2>
    <form method="POST" action="{{ route('admin.rewards.spin.store') }}" class="card grid gap-2 p-3 sm:grid-cols-8 sm:items-end">
        @csrf
        @include('admin.rewards._prize-fields', ['prize' => null])
        <div class="sm:col-span-8"><button class="btn btn-primary btn-sm">Add prize</button></div>
    </form>
@endsection
