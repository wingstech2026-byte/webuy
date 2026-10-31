@php
    $mine = $viewer && $message->user_id === $viewer->id;
    $time = $message->created_at->isToday() ? $message->created_at->format('g:ia') : $message->created_at->format('j M, g:ia');
@endphp

@if ($message->isSystem())
    <div data-message-id="{{ $message->id }}" class="my-3 text-center">
        <span class="inline-block rounded-full bg-stone-200 px-3 py-1 text-xs font-semibold text-stone-600">{{ $message->body }}</span>
    </div>
@elseif ($message->isDeleted())
    <div data-message-id="{{ $message->id }}" class="my-2 text-center text-xs italic text-stone-400">This message was removed.</div>
@else
    <div data-message-id="{{ $message->id }}" class="flex {{ $mine ? 'justify-end' : 'justify-start' }}">
        <div class="max-w-[85%] min-w-0">
            @unless ($mine)
                <p class="mb-0.5 text-xs font-bold {{ $message->user?->is_admin ? 'text-brand-700' : 'text-stone-600' }}">
                    {{ $message->authorLabel($viewer) }}
                    @if ($viewer?->is_admin && $message->user && ! $message->user->is_admin)
                        <span class="font-normal text-stone-400">{{ phone_display($message->user->phone) }}</span>
                        @if ($message->user->isChatBanned()) <span class="badge bg-red-100 text-red-800">blocked</span> @endif
                    @endif
                </p>
            @endunless

            <div class="rounded-2xl px-3 py-2 text-[15px] leading-snug {{ $mine ? 'rounded-br-sm bg-brand-700 text-white' : 'rounded-bl-sm bg-white ring-1 ring-stone-200' }}">
                @if ($message->thumb_url)
                    {{-- Small thumbnail first; the full photo only loads when tapped. --}}
                    <a href="{{ $message->image_url }}" data-full="{{ $message->image_url }}" class="mb-1 block">
                        <img src="{{ $message->thumb_url }}" alt="Photo" loading="lazy" decoding="async" class="max-h-56 rounded-lg object-cover">
                    </a>
                @endif
                @if ($message->audio_url)
                    <audio controls preload="none" src="{{ $message->audio_url }}" class="max-w-full"></audio>
                @endif
                @if ($message->body)
                    <p class="whitespace-pre-line break-words">{{ $message->body }}</p>
                @endif
            </div>

            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 text-[11px] text-stone-400 {{ $mine ? 'justify-end' : '' }}">
                <time datetime="{{ $message->created_at->toIso8601String() }}">{{ $time }}</time>

                @if ($viewer && ! $mine)
                    <form method="POST" action="{{ route('chat.report', $message) }}" data-ajax-action data-done="Reported. Thank you." data-confirm="Report this message to the WiBuy team?">
                        @csrf
                        <button class="underline hover:text-stone-600">Report</button>
                    </form>
                @endif

                @if ($viewer?->is_admin)
                    <form method="POST" action="{{ route('admin.chat.messages.delete', $message) }}" data-ajax-action data-remove-message data-confirm="Remove this message for everyone?">
                        @csrf
                        <button class="font-semibold text-red-600 underline">Delete</button>
                    </form>
                    @if ($message->user && ! $message->user->is_admin)
                        <form method="POST" action="{{ route('admin.chat.users.ban', $message->user) }}" onsubmit="return confirm('{{ $message->user->isChatBanned() ? 'Let this person chat again?' : 'Block this person from chat?' }}')">
                            @csrf
                            <button class="font-semibold text-red-600 underline">{{ $message->user->isChatBanned() ? 'Unblock' : 'Block user' }}</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    </div>
@endif
