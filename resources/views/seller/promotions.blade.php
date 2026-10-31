@extends('seller.layout')

@section('seller')
    <h1 class="mb-1 text-2xl font-extrabold">Promotions</h1>
    <p class="mb-5 text-stone-600">Get more buyers with "Help me cut the price" and free trials. For price cuts you still get your normal price: WiBuy pays the buyer's discount.</p>

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
