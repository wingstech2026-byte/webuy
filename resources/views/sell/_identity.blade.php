{{-- The owner's identity document. $onFile: one was already sent (then it may be left as it is). --}}
<fieldset class="space-y-3 rounded-xl border border-stone-200 p-4">
    <legend class="px-1 font-bold">🪪 Your identity document</legend>
    <p class="text-sm text-stone-600">
        We check every shop owner before approval. Only the {{ config('wibuy.name') }} team sees your document; it is never shown to buyers.
        @if ($onFile) <strong>We already have your document.</strong> Fill this in only to replace it. @endif
    </p>
    <div>
        <label for="owner_nationality" class="label">Your nationality</label>
        <select id="owner_nationality" name="owner_nationality" class="input" @required(! $onFile)>
            @foreach (config('countries') as $country)
                <option value="{{ $country }}" @selected(old('owner_nationality', 'Sierra Leone') === $country)>{{ $country }}</option>
            @endforeach
        </select>
        @error('owner_nationality') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    @php($nationality = old('owner_nationality', \App\Enums\IdentityDocument::SIERRA_LEONE))
    @php($allowed = array_map(fn ($d) => $d->value, \App\Enums\IdentityDocument::allowedFor($nationality)))
    <fieldset data-id-types>
        <legend class="label">Which document are you sending?</legend>
        <p class="mb-1 text-xs text-stone-500" data-id-hint>
            {{ $nationality === \App\Enums\IdentityDocument::SIERRA_LEONE ? 'Sierra Leoneans: your national ID card or your passport.' : 'Your passport, residence permit, your national ID card or another official ID.' }}
        </p>
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach (\App\Enums\IdentityDocument::cases() as $doc)
                {{-- Only the national ID card and passport show for Sierra Leoneans (the server checks it too). --}}
                <label class="choice text-sm" data-id-type="{{ $doc->value }}" @unless (in_array($doc->value, $allowed, true)) hidden @endunless>
                    <input type="radio" name="owner_id_type" value="{{ $doc->value }}" class="size-5 accent-brand-700"
                        @checked(old('owner_id_type', 'national_id') === $doc->value) @disabled(! in_array($doc->value, $allowed, true)) @required(! $onFile)> {{ $doc->label() }}</label>
            @endforeach
        </div>
        @error('owner_id_type') <p class="field-error">{{ $message }}</p> @enderror
    </fieldset>
    <script>
        (() => {
            const nationality = document.getElementById('owner_nationality');
            const box = document.querySelector('[data-id-types]');
            const localOnly = ['national_id', 'passport'];
            const update = () => {
                const local = nationality.value === @json(\App\Enums\IdentityDocument::SIERRA_LEONE);
                box.querySelector('[data-id-hint]').textContent = local
                    ? 'Sierra Leoneans: your national ID card or your passport.'
                    : 'Your passport, residence permit, your national ID card or another official ID.';
                box.querySelectorAll('[data-id-type]').forEach((label) => {
                    const show = !local || localOnly.includes(label.dataset.idType);
                    const input = label.querySelector('input');
                    label.hidden = !show;
                    input.disabled = !show;
                    if (!show && input.checked) {
                        input.checked = false;
                        box.querySelector('input[value=national_id]').checked = true;
                    }
                });
            };
            nationality.addEventListener('change', update);
            update();
        })();
    </script>
    <div>
        <label for="owner_id_number" class="label">Document number (as printed on it)</label>
        <input id="owner_id_number" name="owner_id_number" maxlength="40" autocomplete="off" class="input font-mono uppercase" value="{{ old('owner_id_number') }}" @required(! $onFile)>
        @error('owner_id_number') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="owner_id_front" class="label">Photo of the document (front of the card, or the passport page with your photo)</label>
        <input id="owner_id_front" name="owner_id_front" type="file" accept="image/*" class="block w-full text-sm" @required(! $onFile)>
        <p class="mt-1 text-xs text-stone-500">A clear photo where your name, photo and number can be read.</p>
        @error('owner_id_front') <p class="field-error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="owner_id_back" class="label">Photo of the back of the card (optional; not needed for a passport)</label>
        <input id="owner_id_back" name="owner_id_back" type="file" accept="image/*" class="block w-full text-sm">
        @error('owner_id_back') <p class="field-error">{{ $message }}</p> @enderror
    </div>
</fieldset>
