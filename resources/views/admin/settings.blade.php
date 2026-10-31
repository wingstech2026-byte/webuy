@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Settings</h1>

    <form method="POST" action="{{ route('admin.settings.update') }}" class="card max-w-xl space-y-4 p-5">
        @csrf @method('PUT')
        <div>
            <label for="commission_percent" class="label">Commission (% of each order's item subtotal)</label>
            <input id="commission_percent" name="commission_percent" type="number" step="0.01" min="0" max="50" required class="input" value="{{ old('commission_percent', $commission) }}">
            <p class="mt-1 text-xs text-stone-500">Delivery fees are not charged commission. A seller can have their own rate on their seller page. Changes apply to new orders only.</p>
            @error('commission_percent') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="min_payout" class="label">Minimum payout (Le)</label>
            <input id="min_payout" name="min_payout" type="number" step="0.01" min="0" required class="input" value="{{ old('min_payout', $minPayout) }}">
            @error('min_payout') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <button class="btn btn-primary">Save settings</button>
    </form>
@endsection
