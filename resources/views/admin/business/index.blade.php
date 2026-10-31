@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Business buyers</h1>
    <div class="mb-4 flex gap-2">
        @foreach (['pending', 'verified', 'rejected'] as $s)
            <a href="{{ route('admin.business.index', ['status' => $s]) }}" class="badge py-2 text-sm {{ $status === $s ? 'bg-brand-700 text-white' : 'bg-white ring-1 ring-stone-200' }}">{{ ucfirst($s) }}</a>
        @endforeach
    </div>

    @forelse ($items as $profile)
        <div class="card mb-3 p-4">
            <p class="font-bold">{{ $profile->business_name }} <span class="text-sm font-normal text-stone-600">reg. {{ $profile->registration_no }}</span></p>
            <p class="text-sm text-stone-600">{{ $profile->user->name }} &middot; {{ phone_display($profile->user->phone) }} &middot; {{ $profile->district }} {{ $profile->address }}</p>
            @if ($profile->rejection_reason) <p class="text-sm text-red-700">{{ $profile->rejection_reason }}</p> @endif
            <div class="mt-2 flex flex-wrap gap-2">
                @forelse ($profile->documents as $doc)
                    @if ($doc->status === 'ready')
                        <a href="{{ $doc->url }}" target="_blank" rel="noopener"><img src="{{ $doc->thumb_url }}" alt="Business paper" class="size-24 rounded-lg object-cover"></a>
                    @else
                        <span class="badge bg-stone-100">photo processing</span>
                    @endif
                @empty
                    <span class="text-sm text-stone-500">No papers.</span>
                @endforelse
            </div>
            @if ($profile->status === 'pending')
                <div class="mt-3 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('admin.business.verify', $profile) }}">@csrf<button class="btn btn-primary btn-sm">Verify</button></form>
                    <form method="POST" action="{{ route('admin.business.reject', $profile) }}" class="flex gap-2">@csrf
                        <input name="reason" maxlength="200" class="input min-h-10 max-w-64 py-1" placeholder="Reason" aria-label="Reason">
                        <button class="btn btn-danger btn-sm">Reject</button>
                    </form>
                </div>
            @endif
        </div>
    @empty
        <x-empty title="Nothing here">No business accounts with this status.</x-empty>
    @endforelse
    {{ $items->links() }}
@endsection
