@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Riders and deliveries</h1>

    <form method="POST" action="{{ route('admin.riders.toggle') }}" class="card mb-4 flex max-w-xl flex-wrap items-end gap-2 p-4">
        @csrf
        <div class="flex-1"><label class="label" for="phone">Make someone a rider, or stop them (phone number)</label>
            <input id="phone" name="phone" required inputmode="tel" class="input" placeholder="076 123 456">
            @error('phone') <p class="field-error">{{ $message }}</p> @enderror</div>
        <button class="btn btn-primary">Switch rider on/off</button>
    </form>

    <div class="card mb-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Rider</th><th class="th">Phone</th><th class="th text-right">Delivered</th></tr></thead>
            <tbody>
                @forelse ($riders as $rider)
                    <tr><td class="td">{{ $rider->name }}</td><td class="td">{{ $rider->display_phone }}</td><td class="td text-right">{{ $rider->delivered_count }}</td></tr>
                @empty
                    <tr><td class="td" colspan="3">No riders yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="mb-2 text-lg font-extrabold">Deliveries</h2>
    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Order</th><th class="th">Shop</th><th class="th">Rider</th><th class="th">Status</th><th class="th">Proof</th><th class="th text-right">Fee</th></tr></thead>
            <tbody>
                @forelse ($jobs as $job)
                    <tr>
                        <td class="td"><a class="text-brand-700" href="{{ route('admin.orders.show', $job->order) }}">{{ $job->order->number }}</a></td>
                        <td class="td">{{ $job->seller->shop_name }}</td>
                        <td class="td">{{ $job->rider?->name ?? '—' }}</td>
                        <td class="td">{{ $job->statusLabel() }}</td>
                        <td class="td">
                            @if ($job->proof === 'code') Buyer's code ✓
                            @elseif ($job->proof === 'photo') Photo @foreach ($job->photos as $photo) @if ($photo->url) <a class="text-brand-700 underline" href="{{ $photo->url }}" target="_blank">see</a> @endif @endforeach {{ $job->proof_note }}
                            @else — @endif
                        </td>
                        <td class="td text-right">{{ money($job->fee) }}</td>
                    </tr>
                @empty
                    <tr><td class="td" colspan="6">No deliveries yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $jobs->links() }}</div>
@endsection
