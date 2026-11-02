@extends('layouts.app')

@section('title', 'My details')

@section('content')
    <div class="mx-auto max-w-md">
        <h1 class="mb-1 text-2xl font-extrabold">My details</h1>
        <p class="mb-4 text-stone-600">Your name and where you live. Sellers and riders see your name and the address you give for each order.</p>
        <form method="POST" action="{{ route('account.details.update') }}" class="card space-y-3 p-5">
            @csrf
            <div>
                <label class="label">Phone number</label>
                <p class="font-semibold">{{ $user->display_phone }} <span class="text-xs font-normal text-stone-500">(checked by SMS; it is your login)</span></p>
            </div>
            <div>
                <label for="name" class="label">Full name</label>
                <input id="name" name="name" required maxlength="80" autocomplete="name" class="input" value="{{ old('name', $user->name) }}">
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="district" class="label">District</label>
                <select id="district" name="district" required class="input">
                    <option value="">Choose…</option>
                    @foreach ($districts as $d) <option value="{{ $d }}" @selected(old('district', $user->district) === $d)>{{ $d }}</option> @endforeach
                </select>
                @error('district') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="area" class="label">Town or area</label>
                <input id="area" name="area" required maxlength="80" class="input" value="{{ old('area', $user->area) }}" placeholder="e.g. Lumley, Bo town">
                @error('area') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <button class="btn btn-primary">Save</button>
        </form>
    </div>
@endsection
