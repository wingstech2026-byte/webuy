@extends('layouts.app')

@section('title', $conversation->title)

@section('content')
    <a href="{{ route('chat.index') }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; All chats</a>

    <div class="mx-auto max-w-xl">
        @if ($conversation->type === \App\Enums\ConversationType::Support)
            <p class="mb-3 text-stone-600">Ask us anything about your orders, payments or pickup. We reply during the day, and you will get an SMS if you are away.</p>
        @endif

        @include('chat.panel', $panel + ['title' => $conversation->title])
    </div>
@endsection
