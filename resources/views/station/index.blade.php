@extends('layouts.app')

@section('title', 'My WiBuy station')

@section('content')
    <h1 class="mb-1 text-2xl font-extrabold">📦 {{ $station->name }}</h1>
    <p class="mb-2 text-stone-600">{{ $station->address }} ({{ $station->district }}){{ $station->opening_hours ? ' · '.$station->opening_hours : '' }}{{ $station->is_active ? '' : ' · switched off by WiBuy' }}</p>
    <a href="{{ route('rider.earnings') }}" class="btn btn-outline btn-sm mb-4">💰 My station earnings</a>

    {{-- One box for every step: type the order number from the parcel label, or scan its barcode (a USB scanner types it). --}}
    <div class="grid gap-3 md:grid-cols-3">
        <form method="POST" action="{{ route('station.act', 'from-shop') }}" class="card space-y-2 p-4">
            @csrf
            <h2 class="font-bold">1. A shop brings a parcel</h2>
            <input name="number" required class="input font-mono uppercase" placeholder="Order number, e.g. WB-ABC123" aria-label="Order number from the shop">
            <button class="btn btn-primary btn-sm w-full">Received from the shop</button>
        </form>
        <form method="POST" action="{{ route('station.act', 'from-rider') }}" class="card space-y-2 p-4">
            @csrf
            <h2 class="font-bold">2. A rider brings a parcel</h2>
            <input name="number" required class="input font-mono uppercase" placeholder="Order number" aria-label="Order number from the rider">
            <button class="btn btn-primary btn-sm w-full">Received from the rider</button>
        </form>
        <form method="POST" action="{{ route('station.act', 'hand-over') }}" class="card space-y-2 p-4">
            @csrf
            <h2 class="font-bold">3. A buyer collects</h2>
            <input name="number" required class="input font-mono uppercase" placeholder="Order number" aria-label="Order number the buyer collects">
            <input name="code" required inputmode="numeric" maxlength="4" class="input text-center text-xl tracking-widest" placeholder="Buyer's code" aria-label="The buyer's 4-digit code">
            <button class="btn btn-primary btn-sm w-full">Hand it to the buyer</button>
        </form>
    </div>
    @error('number') <p class="field-error mt-2">{{ $message }}</p> @enderror
    @error('code') <p class="field-error mt-2">{{ $message }}</p> @enderror

    @php($lists = [
        'Shops will bring' => $fromShops->map(fn ($o) => [$o->number, $o->seller->shop_name, 'Accepted by the shop']),
        'Waiting for a rider to collect' => $forRiders->map(fn ($j) => [$j->order->number, $j->isTransfer() ? 'To another WiBuy station' : 'To the buyer\'s door', $j->rider ? 'Rider: '.$j->rider->name.' '.$j->rider->display_phone : 'No rider yet']),
        'Riders will bring' => $fromRiders->map(fn ($j) => [$j->order->number, 'From station '.$j->pickup_name, $j->rider ? 'Rider: '.$j->rider->name : '']),
        'Waiting for the buyer' => $forBuyers->map(fn ($o) => [$o->number, $o->recipient_name, 'Here since '.$o->at_collect_station_at->format('j M, g:ia')]),
    ])
    <div class="mt-6 grid gap-4 md:grid-cols-2">
        @foreach ($lists as $title => $rows)
            <section>
                <h2 class="mb-2 font-extrabold">{{ $title }} ({{ $rows->count() }})</h2>
                <div class="card divide-y divide-stone-100 text-sm">
                    @forelse ($rows as [$number, $who, $note])
                        <div class="flex flex-wrap justify-between gap-2 p-3"><span><strong class="font-mono">{{ $number }}</strong> &middot; {{ $who }}</span><span class="text-stone-500">{{ $note }}</span></div>
                    @empty
                        <p class="p-3 text-stone-500">None right now.</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
@endsection
