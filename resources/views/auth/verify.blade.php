@extends('layouts.app')

@section('title', 'Enter your code')

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="mb-1 text-2xl font-extrabold">Enter your code</h1>
        <p class="mb-6 text-stone-600">We sent a {{ config('wibuy.otp.length') }}-digit code to <strong>{{ phone_display($phone) }}</strong>. It works for {{ config('wibuy.otp.ttl_minutes') }} minutes.</p>

        @if (session('dev_otp'))
            <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Development mode: no SMS was sent. Your code is <strong class="text-lg">{{ session('dev_otp') }}</strong>.
            </div>
        @endif

        <form method="POST" action="{{ route('login.confirm') }}" class="card space-y-4 p-5">
            @csrf
            <div>
                <label for="code" class="label">SMS code</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus
                       maxlength="{{ config('wibuy.otp.length') }}" placeholder="------" class="input text-center text-2xl tracking-[0.5em]">
                @error('code') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            @if ($needsName)
                <div>
                    <label for="name" class="label">Your full name</label>
                    <input id="name" name="name" type="text" autocomplete="name" required maxlength="80"
                           value="{{ old('name') }}" placeholder="e.g. Aminata Kamara" class="input">
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            @endif
            @if ($needsLocation)
                {{-- Where the buyer lives: shops and riders know which area to serve. --}}
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="district" class="label">Your district</label>
                        <select id="district" name="district" required class="input">
                            <option value="">Choose…</option>
                            @foreach ($districts as $d) <option value="{{ $d }}" @selected(old('district') === $d)>{{ $d }}</option> @endforeach
                        </select>
                        @error('district') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="area" class="label">Town or area</label>
                        <input id="area" name="area" required maxlength="80" value="{{ old('area') }}" placeholder="e.g. Lumley, Bo town" class="input">
                        @error('area') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            <button class="btn btn-primary">Continue</button>
        </form>

        <p class="mt-4 text-center text-sm text-stone-600">
            Wrong number or no SMS? <a class="font-semibold text-brand-700 underline" href="{{ route('login') }}">Start again</a>
        </p>
    </div>
@endsection
