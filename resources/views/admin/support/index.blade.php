@extends('admin.layout')

@section('admin')
    <h1 class="mb-1 text-2xl font-extrabold">Support chats</h1>
    <p class="mb-4 text-stone-600">Every customer has one conversation with the WiBuy team. Unread counts are messages from the customer that nobody on the team has opened.</p>

    <div class="space-y-2">
        @forelse ($conversations as $conversation)
            @php
                $last = $conversation->latestMessage;
                $count = $unread[$conversation->id] ?? 0;
            @endphp
            <a href="{{ route('admin.support.show', $conversation) }}" class="card flex items-center gap-3 p-4 transition hover:shadow-md {{ $count > 0 ? 'border-brand-400 bg-brand-50' : '' }}">
                <span class="min-w-0 flex-1">
                    <strong class="block">{{ $conversation->owner->name ?: 'No name yet' }}
                        <span class="font-normal text-stone-500">{{ phone_display($conversation->owner->phone) }}</span>
                        @if ($conversation->owner->isChatBanned()) <span class="badge bg-red-100 text-red-800">blocked</span> @endif
                    </strong>
                    <span class="block truncate text-sm text-stone-600">
                        @if ($last)
                            {{ $last->user_id === $conversation->owner_id ? '' : 'You: ' }}{{ $last->isDeleted() ? 'Message removed' : \Illuminate\Support\Str::limit($last->body ?: '📷 Photo', 70) }}
                        @endif
                    </span>
                </span>
                <span class="shrink-0 text-right text-xs text-stone-500">
                    {{ $conversation->last_message_at->diffForHumans(['short' => true]) }}
                    @if ($count > 0)
                        <span class="mt-1 block"><span class="badge bg-red-600 text-white">{{ $count }} new</span></span>
                    @endif
                </span>
            </a>
        @empty
            <div class="card p-6 text-stone-600">No customer has written to the team yet.</div>
        @endforelse
    </div>
    <div class="mt-4">{{ $conversations->links() }}</div>
@endsection
