@extends('layouts.app')

@section('title', 'Chat')

@section('content')
    <h1 class="mb-4 text-2xl font-extrabold">Chat</h1>

    @php
        $snippet = function ($message) {
            if (! $message) return 'No messages yet';
            if ($message->isDeleted()) return 'Message removed';
            return \Illuminate\Support\Str::limit($message->body ?: ($message->image_path ? '📷 Photo' : ($message->audio_path ? '🎤 Voice note' : '')), 60);
        };
    @endphp

    <div class="mx-auto max-w-xl space-y-3">
        <a href="{{ route('chat.show', $support) }}" class="card flex items-center gap-3 p-4 transition hover:shadow-md">
            <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-brand-700 text-lg font-extrabold text-white">W</span>
            <span class="min-w-0 flex-1">
                <strong class="block">WiBuy team</strong>
                <span class="block truncate text-sm text-stone-600">{{ $snippet($support->latestMessage) }}</span>
            </span>
            @if (($unread[$support->id] ?? 0) > 0)
                <span class="badge bg-red-600 text-white">{{ $unread[$support->id] }}</span>
            @endif
        </a>

        @if ($rooms->isNotEmpty())
            <h2 class="pt-3 text-sm font-bold uppercase tracking-wide text-stone-500">Buyers and sellers</h2>

            @foreach ($rooms as $room)
                @php
                    $conversation = $room->conversation;
                    $other = $conversation->participants->firstWhere('user_id', '!=', auth()->id())?->user;
                @endphp
                <a href="{{ route('chat.show', $conversation) }}" class="card flex items-center gap-3 p-4 transition hover:shadow-md">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-stone-200 text-lg font-extrabold text-stone-700">
                        {{ mb_strtoupper(mb_substr($other?->seller?->shop_name ?? $other?->name ?? '?', 0, 1)) }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <strong class="block truncate">{{ $other?->seller?->shop_name ?? $other?->first_name ?? 'Chat' }}</strong>
                        <span class="block truncate text-sm text-stone-600">{{ $snippet($conversation->latestMessage) }}</span>
                    </span>
                    @if ($room->is_muted)
                        <span class="text-xs text-stone-400">muted</span>
                    @elseif (($unread[$conversation->id] ?? 0) > 0)
                        <span class="badge bg-red-600 text-white">{{ $unread[$conversation->id] }}</span>
                    @endif
                </a>
            @endforeach
        @endif
    </div>
@endsection
