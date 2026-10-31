@extends('layouts.app')

@section('title', 'My inspections')

@section('content')
    <h1 class="mb-4 text-2xl font-extrabold">My inspections</h1>
    @forelse ($jobs as $job)
        <a href="{{ route('agent.inspections.edit', $job) }}" class="card mb-2 block p-4">
            <div class="flex justify-between gap-2">
                <strong>Order {{ $job->order->number }} &middot; {{ $job->order->seller->shop_name }}</strong>
                <span class="badge bg-stone-100">{{ $job->status }}</span>
            </div>
            <p class="text-sm text-stone-600">{{ $job->order->seller->pickup_address }}, {{ $job->order->seller->location }}</p>
            <p class="text-sm">{{ $job->order->items->map(fn ($i) => $i->quantity.' × '.$i->title)->implode(', ') }}</p>
        </a>
    @empty
        <x-empty title="No inspections">The WiBuy team will assign inspections to you.</x-empty>
    @endforelse
@endsection
