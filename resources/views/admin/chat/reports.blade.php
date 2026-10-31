@extends('admin.layout')

@section('admin')
    <h1 class="mb-1 text-2xl font-extrabold">Reported messages</h1>
    <p class="mb-4 text-stone-600">Remove a message for everyone, block the person from chat, or dismiss the reports if the message is fine.</p>

    <div class="space-y-3">
        @forelse ($messages as $message)
            <div class="card p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="font-bold">
                            {{ $message->user?->name ?: 'Unknown' }}
                            <span class="font-normal text-stone-500">{{ $message->user ? phone_display($message->user->phone) : '' }}</span>
                            @if ($message->user?->isChatBanned()) <span class="badge bg-red-100 text-red-800">blocked</span> @endif
                        </p>
                        <p class="text-xs text-stone-500">
                            {{ $message->created_at->format('j M, g:ia') }} in
                            {{ $message->conversation->title }}
                        </p>
                    </div>
                    <span class="badge bg-red-100 text-red-800">{{ $message->open_reports }} {{ \Illuminate\Support\Str::plural('report', $message->open_reports) }}</span>
                </div>

                <div class="mt-3 rounded-xl bg-stone-100 p-3 text-sm">
                    @if ($message->isDeleted())
                        <em class="text-stone-500">Already removed</em>
                    @else
                        <p class="whitespace-pre-line break-words">{{ $message->body }}</p>
                        @if ($message->thumb_url)
                            <a href="{{ $message->image_url }}" target="_blank" rel="noopener"><img src="{{ $message->thumb_url }}" alt="Reported photo" loading="lazy" class="mt-2 max-h-40 rounded-lg"></a>
                        @endif
                    @endif
                </div>

                <ul class="mt-2 text-xs text-stone-500">
                    @foreach ($message->reports as $report)
                        <li>Reported by {{ $report->reporter->name ?: phone_display($report->reporter->phone) }}@if ($report->reason): {{ $report->reason }}@endif</li>
                    @endforeach
                </ul>

                <div class="mt-3 flex flex-wrap gap-2">
                    @unless ($message->isDeleted())
                        <form method="POST" action="{{ route('admin.chat.messages.delete', $message) }}" onsubmit="return confirm('Remove this message for everyone?')">
                            @csrf <button class="btn btn-danger btn-sm">Delete message</button>
                        </form>
                    @endunless
                    @if ($message->user && ! $message->user->is_admin)
                        <form method="POST" action="{{ route('admin.chat.users.ban', $message->user) }}">
                            @csrf <button class="btn btn-danger btn-sm">{{ $message->user->isChatBanned() ? 'Unblock user' : 'Block user' }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.chat.messages.dismiss', $message) }}">
                        @csrf <button class="btn btn-outline btn-sm">Dismiss reports</button>
                    </form>
                </div>
            </div>
        @empty
            <div class="card p-6 text-stone-600">Nothing reported. All quiet.</div>
        @endforelse
    </div>
    <div class="mt-4">{{ $messages->links() }}</div>
@endsection
