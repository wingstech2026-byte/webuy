@php
    // Errors that have no field of their own. Field errors are shown next to the field.
    $generalErrors = collect(['general', 'delete', 'status'])->flatMap(fn ($key) => $errors->get($key));
@endphp

@if (session('status'))
    <div class="mb-4 rounded-xl border border-brand-200 bg-brand-50 px-4 py-3 font-semibold text-brand-900" role="status">
        {{ session('status') }}
    </div>
@endif

@if ($generalErrors->isNotEmpty())
    <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 font-semibold text-red-800" role="alert">
        @foreach ($generalErrors as $message)
            <p>{{ $message }}</p>
        @endforeach
    </div>
@endif
