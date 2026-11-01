@extends('layouts.app')

@section('title', 'Not allowed')

@section('content')
    <div class="card mx-auto max-w-md p-6 text-center">
        <p class="text-4xl">🔒</p>
        <h1 class="mt-2 text-xl font-extrabold">{{ $exception->getMessage() ?: 'You cannot open this page.' }}</h1>
        @auth
            <p class="mt-2 text-stone-600">You are logged in as <strong>{{ auth()->user()->name }}</strong> ({{ auth()->user()->display_phone }}). This account cannot open this page.</p>
            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf
                <button class="btn btn-primary">Log out and use another account</button>
            </form>
        @else
            <a href="{{ route('login') }}" class="btn btn-primary mt-4">Log in</a>
        @endauth
        <a href="{{ route('home') }}" class="mt-3 block text-sm font-semibold text-brand-700">Back to WiBuy</a>
    </div>
@endsection
