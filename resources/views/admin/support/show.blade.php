@extends('admin.layout')

@section('admin')
    <a href="{{ route('admin.support.index') }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; All support chats</a>

    <div class="mx-auto max-w-xl">
        <div class="mb-3 flex items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-extrabold">{{ $conversation->owner->name ?: 'No name yet' }}</h1>
                <p class="text-sm text-stone-600">{{ phone_display($conversation->owner->phone) }}</p>
            </div>
            <form method="POST" action="{{ route('admin.chat.users.ban', $conversation->owner) }}"
                  onsubmit="return confirm('{{ $conversation->owner->isChatBanned() ? 'Let this person chat again?' : 'Block this person from chatting with sellers?' }}')">
                @csrf
                <button class="btn btn-danger btn-sm">{{ $conversation->owner->isChatBanned() ? 'Unblock' : 'Block from chat' }}</button>
            </form>
        </div>

        @include('chat.panel', $panel + ['title' => 'Replying as WiBuy team'])
    </div>
@endsection
