@extends('layouts.app')

@section('title', 'Log in')

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="mb-1 text-2xl font-extrabold">Log in or sign up</h1>
        <p class="mb-6 text-stone-600">Enter your phone number. We will send you a code by SMS. No password needed.</p>

        <form method="POST" action="{{ route('login.send') }}" class="card space-y-4 p-5">
            @csrf
            <div>
                <label for="phone" class="label">Your phone number</label>
                <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required autofocus
                       placeholder="076 123 456" value="{{ old('phone') }}" class="input text-lg">
                @error('phone') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button class="btn btn-primary">Send me a code</button>
        </form>
    </div>
@endsection
