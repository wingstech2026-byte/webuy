<form method="POST" action="{{ $action }}" class="card grid max-w-3xl gap-3 p-4 sm:grid-cols-3">
    @csrf
    <div class="sm:col-span-3">
        <label class="label" for="cut-product">Product</label>
        @isset($products)
            <select id="cut-product" name="product" class="input" required>
                @foreach ($products as $p) <option value="{{ $p->slug }}" @selected(old('product') === $p->slug)>{{ $p->title }} ({{ money($p->price) }})</option> @endforeach
            </select>
        @else
            <input id="cut-product" name="product" required class="input" value="{{ old('product') }}" placeholder="Product link name (the part after /p/)">
        @endisset
        @error('product') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <div><label class="label" for="floor_price">Lowest price (Le)</label><input id="floor_price" name="floor_price" type="number" step="0.01" min="0" required class="input" value="{{ old('floor_price') }}">@error('floor_price') <p class="field-error">{{ $message }}</p> @enderror</div>
    <div><label class="label" for="min_cut">Smallest cut (Le)</label><input id="min_cut" name="min_cut" type="number" step="0.01" min="0.01" required class="input" value="{{ old('min_cut', 2) }}"></div>
    <div><label class="label" for="max_cut">Largest cut (Le)</label><input id="max_cut" name="max_cut" type="number" step="0.01" min="0.01" required class="input" value="{{ old('max_cut', 10) }}">@error('max_cut') <p class="field-error">{{ $message }}</p> @enderror</div>
    <div><label class="label" for="hours">Time limit (hours)</label><input id="hours" name="hours" type="number" min="1" max="168" required class="input" value="{{ old('hours', 24) }}"></div>
    <div><label class="label" for="ends_at">Stop new cuts on (optional)</label><input id="ends_at" name="ends_at" type="datetime-local" class="input" value="{{ old('ends_at') }}"></div>
    <div class="sm:col-span-3"><button class="btn btn-primary">Add price cut</button></div>
</form>
