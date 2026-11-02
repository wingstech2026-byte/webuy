@extends('layouts.app')

@section('title', $product->title)

@section('meta')
    @php($ogDescription = money($product->price).' per '.$product->unit.' from '.$product->seller->shop_name.' ('.$product->seller->district.'). '.config('wibuy.name').': '.config('wibuy.tagline'))
    <meta name="description" content="{{ $ogDescription }}">
    <meta property="og:site_name" content="{{ config('wibuy.name') }}">
    <meta property="og:type" content="product">
    <meta property="og:title" content="{{ $product->title }} – {{ money($product->price) }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:url" content="{{ $shareUrl }}">
    @if ($product->cover)
        <meta property="og:image" content="{{ $product->cover->url }}">
        <meta name="twitter:card" content="summary_large_image">
    @endif
    <meta property="product:price:amount" content="{{ $product->price }}">
    <meta property="product:price:currency" content="{{ config('wibuy.currency') }}">
@endsection

@section('content')
    @unless ($product->isListed())
        <div class="mb-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 font-semibold text-amber-900">
            Preview: buyers cannot see this product ({{ $product->status->label() }}{{ $product->seller->isApproved() ? '' : ', shop not approved' }}).
        </div>
    @endunless

    {{-- App install incentive. The app's own requests never see this (it is a native app, not this page). --}}
    @php($appAmount = \App\Support\RewardSettings::money('app_first_order_amount'))
    @if (\App\Support\Money::gt($appAmount, 0) && preg_match('/Android|iPhone/i', (string) request()->userAgent()))
        <a href="{{ route('app.get', ['path' => '/products/'.$product->slug]) }}"
           class="mb-4 flex items-center justify-between gap-3 rounded-xl bg-brand-700 px-4 py-3 font-bold text-white">
            <span>📱 Get {{ money($appAmount) }} off your first order in the WiBuy app</span>
            <span class="rounded-lg bg-white px-3 py-1 text-sm text-brand-700">Get the app</span>
        </a>
    @endif

    <nav class="mb-3 text-sm font-semibold text-brand-700" aria-label="Breadcrumb">
        <a href="{{ route('home') }}">Home</a>
        @if ($product->category)
            @if ($product->category->parent) &rsaquo; <a href="{{ route('categories.show', $product->category->parent) }}">{{ $product->category->parent->name }}</a> @endif
            &rsaquo; <a href="{{ route('categories.show', $product->category) }}">{{ $product->category->name }}</a>
        @endif
    </nav>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Gallery: only the first picture loads at full size; the rest are lazy thumbnails. --}}
        <div>
            @php($ready = $product->images->where('status', 'ready')->values())
            @if ($product->video_url)
                {{-- Nothing downloads until the buyer presses play (preload none): fine on slow or costly data. --}}
                <div class="card mb-2 overflow-hidden">
                    <video src="{{ $product->video_url }}" controls preload="none" playsinline @if ($ready->isNotEmpty()) poster="{{ $ready[0]->thumb_url }}" @endif class="aspect-square w-full bg-black object-contain" aria-label="Video of {{ $product->title }}"></video>
                    <p class="px-3 py-1 text-xs text-stone-500">🎥 Video from the seller &middot; {{ $product->video_seconds }}s</p>
                </div>
            @endif
            @if ($ready->isEmpty())
                <x-product-image :product="$product" class="aspect-square w-full rounded-2xl" />
            @else
                <div class="card overflow-hidden">
                    <img src="{{ data_saver() ? $ready[0]->thumb_url : $ready[0]->url }}" alt="{{ $product->title }}" class="aspect-square w-full bg-stone-100 object-contain" data-gallery-main>
                </div>
                @if ($ready->count() > 1)
                    <div class="mt-2 flex gap-2 overflow-x-auto pb-1">
                        @foreach ($ready as $i => $image)
                            <a href="{{ $image->url }}" data-gallery-thumb="{{ data_saver() ? $image->thumb_url : $image->url }}" class="block shrink-0 overflow-hidden rounded-lg ring-2 {{ $i === 0 ? 'ring-brand-600' : 'ring-transparent' }}">
                                <img src="{{ $image->thumb_url }}" alt="Picture {{ $i + 1 }}" loading="lazy" decoding="async" class="size-16 object-cover">
                            </a>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        <div class="space-y-4">
            <div>
                <h1 class="text-2xl font-extrabold leading-tight">{{ $product->title }}</h1>
                <div class="mt-1 flex flex-wrap items-center gap-2 text-sm text-stone-600">
                    <x-stars :rating="$product->rating_avg" :count="$product->rating_count" />
                    <span>&middot; {{ $product->condition->label() }}</span>
                    @foreach ($shopOffers['offers'] as $offer) <span class="badge bg-red-50 text-red-700">🏷 {{ $offer->label }}</span> @endforeach
                    @if ($product->seller->isOnHoliday()) <span class="badge bg-amber-100 text-amber-900">🏖 {{ $product->seller->holidayNotice() }}</span> @endif
                    @if ($product->seller->free_delivery_from && $product->seller->offers_delivery) <span class="badge bg-amber-100 text-amber-900">🚚 Free delivery from {{ money($product->seller->free_delivery_from) }}</span> @endif
                    @if ($product->genuine_guarantee) <span class="badge bg-brand-100 text-brand-800" title="If it is fake you get your money back plus compensation">✅ Genuine guarantee</span> @endif
                    @if ($product->sold_count > 0) <span>&middot; {{ $product->sold_count }} sold</span> @endif
                </div>
            </div>

            <div class="card p-4">
                <p class="flex flex-wrap items-baseline gap-x-3">
                    <span class="text-3xl font-extrabold text-brand-700">{{ money($product->price) }}</span>
                    <span class="text-stone-500">per {{ $product->unit }}</span>
                    @if ($product->compare_at_price)
                        <span class="text-stone-400 line-through">{{ money($product->compare_at_price) }}</span>
                        <span class="badge bg-red-600 text-white">-{{ $product->discount_percent }}%</span>
                    @endif
                </p>

                @php($isBusiness = (bool) auth()->user()?->isVerifiedBusiness())
                @php($visibleTiers = $product->tiers->filter(fn ($t) => $isBusiness || ! $t->business_only)->values())
                @if ($visibleTiers->isNotEmpty())
                    <table class="mt-3 w-full text-sm">
                        <caption class="mb-1 text-left font-bold">Wholesale prices</caption>
                        <thead><tr class="text-left text-stone-500"><th class="py-1">Quantity</th><th class="py-1 text-right">Price per {{ $product->unit }}</th></tr></thead>
                        <tbody class="divide-y divide-stone-100">
                            @php($first = $visibleTiers->first()->min_quantity)
                            <tr><td class="py-1">{{ $product->min_order_quantity }}–{{ $first - 1 }}</td><td class="py-1 text-right font-semibold">{{ money($product->price) }}</td></tr>
                            @foreach ($visibleTiers as $i => $tier)
                                @php($next = $visibleTiers[$i + 1] ?? null)
                                <tr>
                                    <td class="py-1">{{ $tier->min_quantity }}{{ $next ? '–'.($next->min_quantity - 1) : '+' }} @if ($tier->business_only) <span class="badge bg-amber-100 text-amber-900">business</span> @endif</td>
                                    <td class="py-1 text-right font-bold text-brand-700">{{ money($tier->unit_price) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <p class="mt-3 text-sm font-semibold {{ $product->stock > 0 ? 'text-brand-800' : 'text-red-700' }}">
                    {{ $product->stock > 0 ? $product->stock.' in stock' : 'Out of stock' }}
                    @if ($product->min_order_quantity > 1) &middot; minimum order {{ $product->min_order_quantity }} @endif
                    @if ($product->lead_time_days) &middot; made to order: ready in {{ $product->lead_time_days }} days @endif
                </p>
                @if (! $isBusiness && $product->tiers->contains('business_only', true))
                    <p class="mt-1 text-sm"><a class="font-semibold text-brand-700" href="{{ route('business.create') }}">Lower prices for verified businesses &rarr;</a></p>
                @endif
                @if ($product->sample_price)
                    <p class="mt-1 text-sm">Try a sample first: one unit for {{ money($product->sample_price) }} (in the app), credited back on your bulk order.</p>
                @endif
                @foreach (app(\App\Services\RiskService::class)->productWarnings($product) as $warning)
                    <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900" role="note">⚠️ {{ $warning['message'] }}</p>
                @endforeach

                @if ($isOwner)
                    <a href="{{ route('seller.products.edit', $product) }}" class="btn btn-outline mt-3">Edit this product</a>
                @elseif ($product->isListed() && $product->stock > 0)
                    <form method="POST" action="{{ route('cart.add', $product) }}" class="mt-3 space-y-3">
                        @csrf
                        @php($options = $product->variants->where('is_active', true))
                        @if ($options->isNotEmpty())
                            <fieldset>
                                <legend class="label">Choose an option</legend>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($options as $option)
                                        <label class="choice text-sm {{ $option->stock < 1 ? 'opacity-50' : '' }}">
                                            <input type="radio" name="variant" value="{{ $option->id }}" required class="size-4 accent-brand-700" @disabled($option->stock < 1) @checked((int) old('variant') === $option->id)>
                                            {{ $option->name }}@if ($option->price !== null) &middot; {{ money($option->price) }}@endif
                                            <span class="text-stone-500">{{ $option->stock < 1 ? '(sold out)' : '('.$option->stock.' left)' }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('variant') <p class="field-error">{{ $message }}</p> @enderror
                            </fieldset>
                        @endif
                        @if (\App\Services\Sms\SmsOrdering::enabled() && config('services.sms_ordering.number') && ! $product->hasVariants() && $product->addons->isEmpty())
                            <p class="text-xs text-stone-500">📱 No data? Text <strong>BUY {{ $product->id }} 1 ORANGE</strong> to {{ config('services.sms_ordering.number') }}@if (config('services.sms_ordering.ussd_code')) or dial {{ config('services.sms_ordering.ussd_code') }} and use code {{ $product->id }}@endif.</p>
                        @endif
                        @if ($product->addons->isNotEmpty())
                            <fieldset>
                                <legend class="label">Extras (optional, for each one)</legend>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($product->addons as $addon)
                                        <label class="choice text-sm"><input type="checkbox" name="addons[]" value="{{ $addon->id }}" class="size-4 accent-brand-700" @checked(in_array($addon->id, (array) old('addons', [])))> {{ $addon->name }} +{{ money($addon->price) }}</label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif
                        <div>
                            <label for="quantity" class="label">How many?</label>
                            <input id="quantity" name="quantity" type="number" inputmode="numeric" min="{{ $product->min_order_quantity }}" max="{{ $product->stock }}"
                                   value="{{ old('quantity', $product->min_order_quantity) }}" class="input max-w-40" required>
                            @error('quantity') <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <button class="btn btn-primary" name="buy_now" value="0">{{ __('Add to cart') }}</button>
                            <button class="btn btn-outline" name="buy_now" value="1">{{ __('Buy now') }}</button>
                        </div>
                        @guest
                            <p class="text-xs text-stone-500">You will be asked to log in with your phone number.</p>
                        @endguest
                    </form>
                @endif
            </div>

            @if ($groupOffer && $product->isListed())
                <div id="buy-together" class="card space-y-3 border-brand-200 bg-brand-50 p-4">
                    <p class="text-lg font-extrabold">👥 Buy together: {{ money($groupOffer->group_price) }} each</p>
                    <p class="text-sm text-stone-700">Instead of {{ money($product->price) }}, when {{ $groupOffer->min_group_size }} people each buy and pay within {{ $groupOffer->hours }} hours. If the group does not fill up, everyone is refunded.</p>
                    @foreach ($openGroups as $g)
                        <a href="{{ route('groups.show', $g) }}" class="flex justify-between rounded-lg bg-white p-2 text-sm ring-1 ring-brand-200">
                            <span>{{ $g->leader->first_name }}'s group: {{ max(0, $groupOffer->min_group_size - $g->paid_count) }} more needed</span>
                            <span class="font-semibold text-brand-700">Join &rarr;</span>
                        </a>
                    @endforeach
                    @auth
                        @if (auth()->id() !== $product->seller->user_id)
                            <details @if ($errors->has('general') || old('recipient_name')) open @endif>
                                <summary class="btn btn-primary btn-sm cursor-pointer">Start my own group</summary>
                                <div class="mt-3">@include('groups._join-form', ['campaign' => $groupOffer->setRelation('product', $product), 'group' => null])</div>
                            </details>
                        @endif
                    @else
                        <a href="{{ route('login') }}" class="btn btn-primary btn-sm">Log in to start a group</a>
                    @endauth
                </div>
            @endif

            <div class="grid gap-2 sm:grid-cols-3">
                <a href="{{ $whatsappUrl }}" target="_blank" rel="noopener" class="btn btn-whatsapp btn-sm w-full">Share on WhatsApp</a>
                <a href="{{ route('products.ask', $product) }}" class="btn btn-outline btn-sm w-full">Ask the seller</a>
                <form method="POST" action="{{ route('compare.toggle', $product) }}">
                    @csrf
                    <button class="btn btn-outline btn-sm w-full">{{ $comparing ? '⚖️ In compare (remove)' : '⚖️ Compare' }}</button>
                </form>
                @auth
                    <form method="POST" action="{{ route('wishlist.toggle', $product) }}">
                        @csrf
                        <button class="btn btn-outline btn-sm w-full">{{ $wishlisted ? '♥ Saved' : '♡ Save' }}</button>
                    </form>
                @endauth
            </div>

            {{-- Seller card --}}
            <x-seller-card :seller="$product->seller" />

            @if ($product->description)
                <div class="card p-4">
                    <h2 class="mb-2 font-bold">{{ __('About this product') }}</h2>
                    <p class="whitespace-pre-line text-stone-700">{{ $product->description }}</p>
                </div>
            @endif
        </div>
    </div>

    @if ($shopOffers['bundles']->isNotEmpty())
        <section class="mt-6">
            <h2 class="mb-2 text-lg font-extrabold">🎁 Bundles: buy together and save</h2>
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach ($shopOffers['bundles'] as $bundle)
                    <form method="POST" action="{{ route('bundles.cart', $bundle) }}" class="card flex flex-wrap items-center justify-between gap-2 p-4">
                        @csrf
                        <div>
                            <p class="font-bold">{{ $bundle->title }}</p>
                            <p class="text-sm text-stone-600">{{ $bundle->products->map(fn ($p) => $p->pivot->quantity.' × '.$p->title)->implode(' + ') }}</p>
                            <p><strong class="text-brand-700">{{ money($bundle->price) }}</strong> <span class="text-sm text-stone-500 line-through">{{ money($bundle->normalPrice()) }}</span></p>
                        </div>
                        <button class="btn btn-primary btn-sm">Add all to cart</button>
                    </form>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Questions and answers --}}
    <section class="mt-8" aria-labelledby="questions">
        <h2 id="questions" class="mb-3 text-xl font-extrabold">{{ __('Questions and answers') }}</h2>
        @forelse ($questions as $q)
            <div class="card mb-2 p-3 text-sm">
                <p class="font-semibold">❓ {{ $q->question }}</p>
                <p class="mt-1">💬 {{ $q->answer }} <span class="text-xs text-stone-500">&middot; {{ $product->seller->shop_name }}</span></p>
            </div>
        @empty
            <p class="mb-2 text-stone-500">No questions yet.</p>
        @endforelse
        @auth
            @unless ($isOwner)
                <form method="POST" action="{{ route('products.questions.store', $product) }}" class="mt-2 flex flex-wrap gap-2">
                    @csrf
                    <input name="question" required minlength="5" maxlength="500" class="input flex-1" value="{{ old('question') }}" placeholder="Ask the shop about this product" aria-label="Your question">
                    <button class="btn btn-outline btn-sm">Ask</button>
                </form>
                @error('question') <p class="field-error">{{ $message }}</p> @enderror
            @endunless
        @else
            <a href="{{ route('login') }}" class="text-sm font-semibold text-brand-700 underline">Log in to ask a question</a>
        @endauth
    </section>

    {{-- Reviews --}}
    <section class="mt-8" aria-labelledby="reviews">
        <h2 id="reviews" class="mb-3 text-xl font-extrabold">{{ __('Reviews') }}</h2>
        @forelse ($reviews as $review)
            <div class="card mb-2 p-4">
                <div class="flex items-center justify-between gap-2">
                    <strong>{{ $review->buyer->first_name }}
                        @if ($review->is_free_trial) <span class="badge ml-1 bg-amber-100 text-amber-900" title="This buyer got the product free or almost free to test it">Free trial</span> @endif
                    </strong>
                    <x-stars :rating="$review->rating" />
                </div>
                @if ($review->comment) <p class="mt-1 text-stone-700">{{ $review->comment }}</p> @endif
                @if ($review->images->isNotEmpty())
                    <div class="mt-2 flex gap-2">
                        @foreach ($review->images->where('status', 'ready') as $photo)
                            <img src="{{ $photo->thumb_url }}" alt="Photo from the review" loading="lazy" class="size-20 rounded-lg object-cover">
                        @endforeach
                    </div>
                @endif
                <p class="mt-1 text-xs text-stone-400">{{ $review->created_at->format('j M Y') }}</p>
            </div>
        @empty
            <p class="text-stone-500">No reviews yet. Buyers can review after their order is completed.</p>
        @endforelse
    </section>

    @if ($alsoBought->isNotEmpty())
        <section class="mt-8" aria-labelledby="also-bought">
            <h2 id="also-bought" class="mb-3 text-xl font-extrabold">{{ __('People who bought this also bought') }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($alsoBought as $other)
                    <x-product-card :product="$other" />
                @endforeach
            </div>
        </section>
    @endif
    @if ($more->isNotEmpty())
        <section class="mt-8">
            <h2 class="mb-3 text-xl font-extrabold">More from {{ $product->seller->shop_name }}</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($more as $item)
                    <x-product-card :product="$item" />
                @endforeach
            </div>
        </section>
    @endif
    @if ($recent->isNotEmpty())
        <section class="mt-8" aria-labelledby="recent">
            <div class="mb-3 flex items-center justify-between">
                <h2 id="recent" class="text-lg font-extrabold">{{ __('You looked at') }}</h2>
                <a href="{{ route('compare.index') }}" class="text-sm font-semibold text-brand-700">Compare &rarr;</a>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ($recent->take(4) as $item) <x-product-card :product="$item" /> @endforeach
            </div>
        </section>
    @endif
@endsection
