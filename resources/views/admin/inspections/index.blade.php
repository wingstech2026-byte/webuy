@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Pre-delivery inspections</h1>

    <form method="POST" action="{{ route('admin.agents.toggle') }}" class="card mb-5 flex flex-wrap items-end gap-2 p-4">
        @csrf
        <div><label for="phone" class="label">Add or remove an inspection agent (phone)</label><input id="phone" name="phone" required class="input" placeholder="076 123 456"></div>
        <button class="btn btn-outline btn-sm">Add / remove agent</button>
        @error('phone') <p class="field-error w-full">{{ $message }}</p> @enderror
        <p class="w-full text-sm text-stone-500">Agents: {{ $agents->map(fn ($a) => $a->name ?? phone_display($a->phone))->implode(', ') ?: 'none yet' }}. They send reports from <code>/agent/inspections</code>.</p>
    </form>

    @forelse ($inspections as $inspection)
        <div class="card mb-3 p-4">
            <div class="flex flex-wrap justify-between gap-2">
                <p class="font-bold"><a class="text-brand-700" href="{{ route('admin.orders.show', $inspection->order) }}">Order {{ $inspection->order->number }}</a> &middot; {{ $inspection->order->seller->shop_name }} &middot; fee {{ money($inspection->fee) }}</p>
                <span class="badge {{ $inspection->status === 'passed' ? 'bg-brand-50 text-brand-800' : ($inspection->status === 'failed' ? 'bg-red-50 text-red-800' : 'bg-stone-100') }}">{{ str_replace('_', ' ', $inspection->status) }}</span>
            </div>
            <p class="text-sm text-stone-600">Buyer {{ $inspection->order->buyer->name }} &middot; agent {{ $inspection->agent?->name ?? 'not assigned' }}</p>
            @if (in_array($inspection->status, ['waiting', 'assigned'], true) && $agents->isNotEmpty())
                <form method="POST" action="{{ route('admin.inspections.assign', $inspection) }}" class="mt-2 flex gap-2">@csrf
                    <select name="agent_id" class="input max-w-64" aria-label="Agent">@foreach ($agents as $a) <option value="{{ $a->id }}" @selected($inspection->agent_id === $a->id)>{{ $a->name ?? phone_display($a->phone) }}</option> @endforeach</select>
                    <button class="btn btn-primary btn-sm">Assign</button>
                </form>
            @endif
            @if ($inspection->report)
                <p class="mt-2 text-sm">{{ $inspection->report }}</p>
                <div class="mt-2 flex gap-2">@foreach ($inspection->photos->where('status', 'ready') as $photo) <img src="{{ $photo->thumb_url }}" alt="Inspection photo" class="size-20 rounded-lg object-cover"> @endforeach</div>
            @endif
        </div>
    @empty
        <x-empty title="No inspections">Buyers book inspections for big orders paid in parts.</x-empty>
    @endforelse
    {{ $inspections->links() }}
@endsection
