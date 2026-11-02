@extends('seller.layout')

@section('seller')
    <h1 class="mb-1 text-2xl font-extrabold">Questions from buyers</h1>
    <p class="mb-4 text-stone-600">Your answer shows on the product page for everyone. Do not share phone numbers or ways to pay outside WiBuy.</p>

    <h2 class="mb-2 font-extrabold">Waiting for your answer ({{ $open->count() }})</h2>
    @forelse ($open as $question)
        <div class="card mb-3 space-y-2 p-4">
            <p class="text-sm text-stone-500"><a class="font-semibold text-brand-700" href="{{ route('products.show', $question->product) }}">{{ $question->product->title }}</a> &middot; {{ $question->asker->first_name ?? 'A buyer' }} &middot; {{ $question->created_at->diffForHumans() }}</p>
            <p class="font-semibold">❓ {{ $question->question }}</p>
            <form method="POST" action="{{ route('seller.questions.answer', $question) }}" class="space-y-2">
                @csrf
                <textarea name="answer" rows="2" maxlength="1000" required class="input" placeholder="Your answer"></textarea>
                <div class="flex gap-2">
                    <button class="btn btn-primary btn-sm">Answer</button>
                    <button form="hide-{{ $question->id }}" class="btn btn-outline btn-sm">Hide</button>
                </div>
            </form>
            <form id="hide-{{ $question->id }}" method="POST" action="{{ route('seller.questions.hide', $question) }}">@csrf</form>
        </div>
    @empty
        <p class="card mb-4 p-4 text-stone-500">No questions waiting.</p>
    @endforelse
    @error('answer') <p class="field-error">{{ $message }}</p> @enderror

    <h2 class="mb-2 mt-6 font-extrabold">Answered</h2>
    <div class="card divide-y divide-stone-100">
        @forelse ($answered as $question)
            <div class="p-3 text-sm"><p class="font-semibold">❓ {{ $question->question }}</p><p>💬 {{ $question->answer }}</p><p class="text-xs text-stone-500">{{ $question->product->title }}</p></div>
        @empty
            <p class="p-3 text-stone-500">Nothing yet.</p>
        @endforelse
    </div>
@endsection
