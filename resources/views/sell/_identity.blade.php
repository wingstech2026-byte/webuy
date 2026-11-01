{{-- The owner's national ID card. $onFile: one was already sent (then it may be left as it is). --}}
<fieldset class="space-y-3 rounded-xl border border-stone-200 p-4">
    <legend class="px-1 font-bold">🪪 Your national ID card</legend>
    <p class="text-sm text-stone-600">
        We check every shop owner before approval. Only the {{ config('wibuy.name') }} team sees your ID card; it is never shown to buyers.
        @if ($onFile) <strong>We already have your ID card.</strong> Fill this in only to replace it. @endif
    </p>
    <div>
        <label for="owner_id_number" class="label">ID card number (the serial number printed on the card)</label>
        <input id="owner_id_number" name="owner_id_number" maxlength="40" autocomplete="off" class="input font-mono uppercase" value="{{ old('owner_id_number') }}" @required(! $onFile)>
        @error('owner_id_number') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="owner_id_front" class="label">Photo of the front of the card</label>
        <input id="owner_id_front" name="owner_id_front" type="file" accept="image/*" class="block w-full text-sm" @required(! $onFile)>
        <p class="mt-1 text-xs text-stone-500">A clear photo where your name, photo and number can be read.</p>
        @error('owner_id_front') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="owner_id_back" class="label">Photo of the back of the card (optional)</label>
        <input id="owner_id_back" name="owner_id_back" type="file" accept="image/*" class="block w-full text-sm">
        @error('owner_id_back') <p class="field-error">{{ $message }}</p> @enderror
    </div>
</fieldset>
