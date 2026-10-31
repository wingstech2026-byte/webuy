{{--
    The chat box. Works without JavaScript (page shows the latest messages, the form posts and reloads).
    With JavaScript: live updates (WebSocket, or polling every few seconds), older messages on scroll-up.
    Variables: the array from ChatService::panel(), plus $title.
--}}
@php
    $c = $conversation;
    $realtime = config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key'));
@endphp

<section id="chat" class="card flex flex-col overflow-hidden" data-chat
         data-messages-url="{{ route('chat.messages', $c) }}"
         data-read-url="{{ route('chat.read', $c) }}"
         data-ping-url="{{ route('chat.ping') }}"
         data-channel="conversation.{{ $c->id }}"
         data-realtime="{{ $realtime ? '1' : '0' }}"
         data-poll="{{ config('chat.poll_seconds') * 1000 }}"
         data-first-id="{{ $messages->first()?->id }}"
         data-last-id="{{ $messages->last()?->id }}"
         data-has-more="{{ $hasMore ? '1' : '0' }}">

    <header class="flex items-center justify-between gap-3 border-b border-stone-200 px-4 py-3">
        <div class="min-w-0">
            <h2 class="truncate text-lg font-extrabold">{{ $title ?? $c->title }}</h2>
            <p class="text-xs text-stone-500"><span data-chat-live>Chat</span></p>
        </div>
        @if ($viewer && ! $viewer->is_admin)
            <form method="POST" action="{{ route('chat.mute', $c) }}">
                @csrf
                <button class="text-xs font-semibold text-stone-500 underline">{{ $muted ? 'Unmute' : 'Mute' }}</button>
            </form>
        @endif
    </header>

    <div data-chat-list class="max-h-96 min-h-40 space-y-2 overflow-y-auto bg-stone-50 px-3 py-3" aria-live="polite">
        <button type="button" data-load-older class="{{ $hasMore ? '' : 'hidden' }} mx-auto block rounded-full bg-white px-3 py-1 text-xs font-bold text-brand-700 ring-1 ring-stone-200">
            Load older messages
        </button>
        @if ($messages->isEmpty())
            <p data-chat-empty class="py-6 text-center text-sm text-stone-500">No messages yet. Say hello!</p>
        @endif
        @include('chat.messages', ['messages' => $messages, 'viewer' => $viewer, 'conversation' => $c])
    </div>

    <p data-chat-error class="{{ $errors->hasAny(['body', 'image', 'voice']) ? '' : 'hidden' }} px-4 pt-2 text-sm font-semibold text-red-700" role="alert">
        {{ $errors->first('body') ?: ($errors->first('image') ?: $errors->first('voice')) }}
    </p>

    @if ($blockedReason)
        <p class="border-t border-stone-200 px-4 py-3 text-sm font-semibold text-stone-600">
            {{ $blockedReason }}
            @unless ($viewer)
                <a href="{{ route('login') }}" class="text-brand-700 underline">Log in</a>
            @endunless
        </p>
    @else
        <form method="POST" action="{{ route('chat.store', $c) }}" enctype="multipart/form-data" data-chat-form
              class="space-y-2 border-t border-stone-200 p-3">
            @csrf
            <textarea name="body" rows="2" maxlength="{{ config('chat.max_length') }}" placeholder="Write a message" aria-label="Message"
                      class="input min-h-0">{{ old('body') }}</textarea>
            <div class="flex flex-wrap items-center gap-2">
                <label class="btn btn-outline btn-sm cursor-pointer">
                    📷 Photo
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="sr-only" data-file-input>
                </label>
                @if ($canVoice)
                    <label class="btn btn-outline btn-sm cursor-pointer">
                        🎤 Voice note
                        <input type="file" name="voice" accept="audio/*" capture class="sr-only" data-file-input>
                    </label>
                @endif
                <span data-file-name class="min-w-0 truncate text-xs text-stone-500"></span>
                <button type="submit" class="btn btn-primary btn-sm ml-auto">Send</button>
            </div>
        </form>
    @endif
</section>
