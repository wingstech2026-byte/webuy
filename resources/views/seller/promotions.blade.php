@extends('seller.layout')

@section('seller')
    <h1 class="mb-1 text-2xl font-extrabold">Promotions</h1>
    <p class="mb-5 text-stone-600">Get more buyers with "Help me cut the price" and free trials. For price cuts you still get your normal price: WiBuy pays the buyer's discount.</p>

    {{-- The shop's own offers: paid by the shop, from the sale --}}
    <h2 class="mb-2 text-lg font-extrabold">🏷 Shop offers</h2>
    <p class="mb-2 text-sm text-stone-600">You pay these from the sale: the buyer's total and your earnings go down by the discount.</p>
    @foreach ($offers as $offer)
        <div class="card mb-2 flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
            <span><strong>{{ $offer->label }}</strong> &middot; used {{ $offer->uses }}{{ $offer->max_uses ? '/'.$offer->max_uses : '' }} &middot; {{ $offer->ends_at ? 'until '.$offer->ends_at->format('j M') : 'no end date' }}</span>
            <form method="POST" action="{{ route('seller.offers.toggle', $offer) }}">@csrf<button class="btn btn-sm {{ $offer->is_active ? 'btn-outline' : 'btn-primary' }}">{{ $offer->is_active ? 'Switch off' : 'Switch on' }}</button></form>
        </div>
    @endforeach
    <form method="POST" action="{{ route('seller.offers.store') }}" class="card mb-8 grid gap-2 p-4 sm:grid-cols-3">
        @csrf
        <div><label class="label" for="offer-kind">Kind</label>
            <select id="offer-kind" name="kind" class="input"><option value="spend">Spend X, save Y (automatic)</option><option value="code">A code you share</option><option value="bogo">Buy N, get 1 free</option></select></div>
        <div><label class="label" for="offer-code">Code (for a code)</label><input id="offer-code" name="code" maxlength="20" class="input uppercase" placeholder="FRIDAY10" value="{{ old('code') }}"></div>
        <div><label class="label" for="offer-min">Minimum spend (Le)</label><input id="offer-min" name="min_spend" type="number" min="0" class="input" value="{{ old('min_spend') }}"></div>
        <div><label class="label" for="offer-amount">Le off</label><input id="offer-amount" name="amount" type="number" min="1" class="input" value="{{ old('amount') }}"></div>
        <div><label class="label" for="offer-percent">or % off</label><input id="offer-percent" name="percent" type="number" min="1" max="90" class="input" value="{{ old('percent') }}"></div>
        <div><label class="label" for="offer-max">Max discount (Le, for %)</label><input id="offer-max" name="max_discount" type="number" min="1" class="input" value="{{ old('max_discount') }}"></div>
        <div><label class="label" for="offer-product">Product (buy N get 1)</label>
            <select id="offer-product" name="product" class="input"><option value="">—</option>@foreach ($products as $p) <option value="{{ $p->id }}">{{ $p->title }}</option> @endforeach</select></div>
        <div><label class="label" for="offer-buy">Buy how many (N)</label><input id="offer-buy" name="buy_quantity" type="number" min="1" max="20" class="input" value="{{ old('buy_quantity', 2) }}"></div>
        <div><label class="label" for="offer-end">Ends (optional)</label><input id="offer-end" name="ends_at" type="date" class="input" value="{{ old('ends_at') }}"></div>
        @foreach (['kind', 'code', 'amount', 'percent', 'product', 'buy_quantity', 'ends_at'] as $field) @error($field) <p class="field-error sm:col-span-3">{{ $message }}</p> @enderror @endforeach
        <button class="btn btn-primary sm:col-span-3">Start the offer</button>
    </form>

    <h2 class="mb-2 text-lg font-extrabold">🎁 Bundles</h2>
    @foreach ($bundles as $bundle)
        <div class="card mb-2 flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
            <span><strong>{{ $bundle->title }}</strong> &middot; {{ $bundle->products->map(fn ($p) => $p->pivot->quantity.' × '.$p->title)->implode(' + ') }} &middot; {{ money($bundle->price) }} (normally {{ money($bundle->normalPrice()) }})</span>
            <form method="POST" action="{{ route('seller.bundles.toggle', $bundle) }}">@csrf<button class="btn btn-sm {{ $bundle->is_active ? 'btn-outline' : 'btn-primary' }}">{{ $bundle->is_active ? 'Switch off' : 'Switch on' }}</button></form>
        </div>
    @endforeach
    <form method="POST" action="{{ route('seller.bundles.store') }}" class="card mb-8 space-y-2 p-4">
        @csrf
        <div class="grid gap-2 sm:grid-cols-2">
            <div><label class="label" for="bundle-title">Name</label><input id="bundle-title" name="title" required maxlength="80" class="input" placeholder="Cooking pack" value="{{ old('title') }}"></div>
            <div><label class="label" for="bundle-price">Bundle price (Le)</label><input id="bundle-price" name="price" type="number" min="1" required class="input" value="{{ old('price') }}"></div>
        </div>
        @for ($i = 0; $i < 4; $i++)
            <div class="grid grid-cols-[1fr_6rem] gap-2">
                <select name="items[{{ $i }}][product]" class="input" aria-label="Product {{ $i + 1 }}"><option value="">{{ $i < 2 ? 'Choose a product' : '(optional)' }}</option>@foreach ($products as $p) <option value="{{ $p->id }}">{{ $p->title }} · {{ money($p->price) }}</option> @endforeach</select>
                <input name="items[{{ $i }}][quantity]" type="number" min="1" value="1" class="input" aria-label="How many">
            </div>
        @endfor
        @error('bundle') <p class="field-error">{{ $message }}</p> @enderror
        <button class="btn btn-primary">Create the bundle</button>
    </form>

    <h2 class="mb-2 text-lg font-extrabold">Cut the price</h2>
    @foreach ($cuts as $c)
        <div class="card mb-2 flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
            <span><strong>{{ $c->product->title }}</strong> &middot; {{ money($c->product->price) }} &rarr; {{ money($c->floor_price) }} &middot; {{ $c->hours }} h &middot; {{ $c->cuts_count }} started</span>
            <form method="POST" action="{{ route('seller.promotions.cuts.toggle', $c) }}">@csrf<button class="btn btn-sm {{ $c->is_active ? 'btn-outline' : 'btn-primary' }}">{{ $c->is_active ? 'Switch off' : 'Switch on' }}</button></form>
        </div>
    @endforeach
    @if ($products->isEmpty())
        <p class="card p-4">Publish a product first.</p>
    @else
        @include('admin.rewards._cut-form', ['action' => route('seller.promotions.cuts.store'), 'products' => $products])
    @endif

    <h2 class="mb-2 mt-8 text-lg font-extrabold">Free trials</h2>
    @foreach ($trials as $trial)
        <div class="card mb-3 p-4">
            <p class="font-bold">{{ $trial->product->title }}</p>
            <p class="text-sm text-stone-600">Trial price {{ money($trial->trial_price) }} &middot; {{ $trial->selectedCount() }}/{{ $trial->slots }} places &middot; {{ $trial->isOpen() ? 'apply until '.$trial->apply_until->format('j M') : 'closed' }}</p>
            @include('admin.rewards._trial-applications', ['campaign' => $trial, 'selectRoute' => 'seller.promotions.trials.select', 'rejectRoute' => 'seller.promotions.trials.reject'])
        </div>
    @endforeach
    @if ($products->isNotEmpty())
        <form method="POST" action="{{ route('seller.promotions.trials.store') }}" class="card grid max-w-3xl gap-3 p-4 sm:grid-cols-4">
            @csrf
            <div class="sm:col-span-4"><label class="label" for="trial-product">Product</label>
                <select id="trial-product" name="product" class="input" required>@foreach ($products as $p) <option value="{{ $p->slug }}">{{ $p->title }} ({{ money($p->price) }})</option> @endforeach</select>
            </div>
            <div><label class="label" for="trial_price">Trial price (Le, 0 = free)</label><input id="trial_price" name="trial_price" type="number" min="0" step="0.01" required class="input" value="{{ old('trial_price', 0) }}">@error('trial_price') <p class="field-error">{{ $message }}</p> @enderror</div>
            <div><label class="label" for="slots">Testers</label><input id="slots" name="slots" type="number" min="1" max="100" required class="input" value="{{ old('slots', 5) }}"></div>
            <div><label class="label" for="days_open">Open for (days)</label><input id="days_open" name="days_open" type="number" min="1" max="60" required class="input" value="{{ old('days_open', 7) }}"></div>
            <div class="flex items-end"><button class="btn btn-primary">Create free trial</button></div>
            <p class="text-xs text-stone-500 sm:col-span-4">You pay for trial units yourself (you receive the trial price). Testers must post an honest review with photos within {{ \App\Support\RewardSettings::int('trial_review_days') }} days of receiving it; it is marked "Free trial".</p>
        </form>
    @endif
@endsection
