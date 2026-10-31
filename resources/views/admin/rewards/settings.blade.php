@extends('admin.layout')

@section('admin')
    <h1 class="mb-3 text-2xl font-extrabold">Rewards and limits</h1>
    @include('admin.rewards._nav')

    <form method="POST" action="{{ route('admin.rewards.settings.update') }}" class="max-w-2xl space-y-4">
        @csrf @method('PUT')
        @foreach ($groups as $group => $fields)
            <fieldset class="card space-y-3 p-5">
                <legend class="px-1 text-lg font-extrabold">{{ $group }}</legend>
                @foreach ($fields as $key => [, $label, $type])
                    <div>
                        @if ($type === 'bool')
                            <label class="choice"><input type="checkbox" name="{{ $key }}" value="1" class="size-5 accent-brand-700" @checked(old($key, $values[$key]))> {{ $label }}</label>
                        @else
                            <label for="{{ $key }}" class="label">{{ $label }}</label>
                            <input id="{{ $key }}" name="{{ $key }}" class="input" required
                                   @if ($type === 'int') type="number" min="0" step="1" @elseif ($type === 'money') type="number" min="0" step="0.01" @else type="text" maxlength="500" @endif
                                   value="{{ old($key, $values[$key]) }}">
                        @endif
                        @error($key) <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </fieldset>
        @endforeach
        <button class="btn btn-primary">Save reward settings</button>
    </form>
@endsection
