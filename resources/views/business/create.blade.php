@extends('layouts.app')

@section('title', 'Business account')

@section('content')
    <div class="mx-auto max-w-xl">
        <h1 class="mb-1 text-2xl font-extrabold">Business buyer account</h1>
        <p class="mb-4 text-stone-600">Buy for your shop, school, building site or company. Once WiBuy checks your papers you see business-only wholesale prices, ask sellers for quotes and can pay big orders in parts.</p>

        @if ($profile?->isVerified())
            <div class="card p-4 font-semibold text-brand-800">✓ {{ $profile->business_name }} is verified.</div>
        @else
            @if ($profile?->status === 'pending')
                <div class="card mb-4 p-4">We are checking {{ $profile->business_name }}. You can send new papers below if needed.</div>
            @elseif ($profile?->status === 'rejected')
                <div class="card mb-4 border-red-200 bg-red-50 p-4 text-red-800">Not approved: {{ $profile->rejection_reason }}. Please correct and send again.</div>
            @endif

            <form method="POST" action="{{ route('business.store') }}" enctype="multipart/form-data" class="card space-y-4 p-5">
                @csrf
                <div>
                    <label for="business_name" class="label">Business name</label>
                    <input id="business_name" name="business_name" required maxlength="120" class="input" value="{{ old('business_name', $profile?->business_name) }}">
                    @error('business_name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="registration_no" class="label">Registration number (OARG / CAC / NRA)</label>
                    <input id="registration_no" name="registration_no" required maxlength="60" class="input" value="{{ old('registration_no', $profile?->registration_no) }}">
                    @error('registration_no') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="district" class="label">District</label>
                    <select id="district" name="district" class="input">
                        @foreach ($districts as $d) <option value="{{ $d }}" @selected(old('district', $profile?->district) === $d)>{{ $d }}</option> @endforeach
                    </select>
                </div>
                <div>
                    <label for="address" class="label">Business address</label>
                    <input id="address" name="address" maxlength="200" class="input" value="{{ old('address', $profile?->address) }}">
                </div>
                <div>
                    <label for="documents" class="label">Photos of your papers (registration certificate; 1 to 4)</label>
                    <input id="documents" name="documents[]" type="file" multiple required accept="image/jpeg,image/png,image/webp" class="input">
                    @error('documents') <p class="field-error">{{ $message }}</p> @enderror
                    @error('documents.*') <p class="field-error">{{ $message }}</p> @enderror
                    <p class="mt-1 text-xs text-stone-500">The papers are shown only on the WiBuy team's admin pages (stored under random, unlisted file names).</p>
                </div>
                <button class="btn btn-primary">Send for checking</button>
            </form>
        @endif
    </div>
@endsection
