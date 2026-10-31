@extends('seller.layout')

@section('seller')
    @php
        $tiers = old('tiers', $item->exists ? $item->tiers->map(fn ($t) => ['min_quantity' => $t->min_quantity, 'unit_price' => $t->unit_price, 'business_only' => $t->business_only])->all() : []);
        // Always offer a few empty tier rows; blank rows are ignored.
        $tiers = array_pad(array_values($tiers), max(3, count($tiers) + 1), ['min_quantity' => '', 'unit_price' => '']);
        $images = $item->exists ? $item->images : collect();
    @endphp

    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-extrabold">{{ $item->exists ? 'Edit product' : 'Add a product' }}</h1>
        @if ($item->exists)
            <a href="{{ route('products.show', $item) }}" class="text-sm font-semibold text-brand-700">See it as buyers do &rarr;</a>
        @endif
    </div>

    @if ($item->exists && $item->status === \App\Enums\ProductStatus::Suspended)
        <p class="mb-4 rounded-xl bg-red-50 p-3 font-semibold text-red-800">The WiBuy team suspended this product, so buyers cannot see it. Contact us if you think this is a mistake.</p>
    @endif

    <form method="POST" enctype="multipart/form-data" class="grid gap-4 lg:grid-cols-[1fr_20rem]"
          action="{{ $item->exists ? route('seller.products.update', $item) : route('seller.products.store') }}">
        @csrf
        @if ($item->exists) @method('PUT') @endif

        <div class="space-y-4">
            <div class="card space-y-4 p-5">
                <div>
                    <label for="title" class="label">Title</label>
                    <input id="title" name="title" required maxlength="120" class="input" value="{{ old('title', $item->title) }}" placeholder="e.g. Local rice, 50kg bag">
                    @error('title') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="category_id" class="label">Category</label>
                    <select id="category_id" name="category_id" class="input">
                        <option value="">Choose…</option>
                        @foreach ($categories as $parent)
                            <optgroup label="{{ $parent->name }}">
                                <option value="{{ $parent->id }}" @selected((int) old('category_id', $item->category_id) === $parent->id)>{{ $parent->name }} (general)</option>
                                @foreach ($parent->children as $child)
                                    <option value="{{ $child->id }}" @selected((int) old('category_id', $item->category_id) === $child->id)>{{ $child->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('category_id') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="description" class="label">Description</label>
                    <textarea id="description" name="description" rows="5" maxlength="4000" class="input">{{ old('description', $item->description) }}</textarea>
                    @error('description') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="card space-y-4 p-5">
                <h2 class="font-bold">Price and stock</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="price" class="label">Price per unit (Le)</label>
                        <input id="price" name="price" type="number" step="0.01" min="0.01" inputmode="decimal" required class="input" value="{{ old('price', $item->price) }}">
                        @error('price') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="compare_at_price" class="label">Old price (optional, shows a discount)</label>
                        <input id="compare_at_price" name="compare_at_price" type="number" step="0.01" min="0" inputmode="decimal" class="input" value="{{ old('compare_at_price', $item->compare_at_price) }}">
                        @error('compare_at_price') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="member_price" class="label">WiBuy Pass member price (optional)</label>
                        <input id="member_price" name="member_price" type="number" step="0.01" min="0" inputmode="decimal" class="input" value="{{ old('member_price', $item->member_price) }}">
                        <p class="mt-1 text-xs text-stone-500">A lower price for WiBuy Pass members. You receive this price for their orders.</p>
                        @error('member_price') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="unit" class="label">Unit</label>
                        <input id="unit" name="unit" required maxlength="40" class="input" value="{{ old('unit', $item->unit) }}" placeholder="piece, 50kg bag, carton…">
                        @error('unit') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="stock" class="label">Units in stock</label>
                        <input id="stock" name="stock" type="number" min="0" inputmode="numeric" required class="input" value="{{ old('stock', $item->stock) }}">
                        @error('stock') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="min_order_quantity" class="label">Minimum order</label>
                        <input id="min_order_quantity" name="min_order_quantity" type="number" min="1" inputmode="numeric" required class="input" value="{{ old('min_order_quantity', $item->min_order_quantity) }}">
                        @error('min_order_quantity') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="condition" class="label">Condition</label>
                        <select id="condition" name="condition" class="input">
                            @foreach ($conditions as $condition)
                                <option value="{{ $condition->value }}" @selected(old('condition', $item->condition?->value ?? 'new') === $condition->value)>{{ $condition->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="card space-y-3 p-5">
                <h2 class="font-bold">Wholesale prices (optional)</h2>
                <p class="text-sm text-stone-600">Cheaper per unit when buyers order more. Example: from 10 units Le 90, from 50 units Le 80.</p>
                @error('tiers') <p class="field-error">{{ $message }}</p> @enderror
                @foreach ($tiers as $i => $tier)
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="label" for="tier-q-{{ $i }}">From quantity</label>
                            <input id="tier-q-{{ $i }}" name="tiers[{{ $i }}][min_quantity]" type="number" min="2" inputmode="numeric" class="input" value="{{ $tier['min_quantity'] }}">
                            @error("tiers.$i.min_quantity") <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="tier-p-{{ $i }}">Price per unit (Le)</label>
                            <input id="tier-p-{{ $i }}" name="tiers[{{ $i }}][unit_price]" type="number" step="0.01" min="0.01" inputmode="decimal" class="input" value="{{ $tier['unit_price'] }}">
                            @error("tiers.$i.unit_price") <p class="field-error">{{ $message }}</p> @enderror
                        </div>
                        <label class="col-span-2 flex items-center gap-2 text-sm">
                            <input type="checkbox" name="tiers[{{ $i }}][business_only]" value="1" class="size-4 accent-brand-700" @checked(! empty($tier['business_only']))>
                            Only for verified business buyers
                        </label>
                    </div>
                @endforeach
            </div>

            <div class="card space-y-3 p-5">
                <h2 class="font-bold">Business buyers (optional)</h2>
                <div>
                    <label for="lead_time_days" class="label">Made to order: ready in (days)</label>
                    <input id="lead_time_days" name="lead_time_days" type="number" min="1" max="365" inputmode="numeric" class="input" value="{{ old('lead_time_days', $item->lead_time_days) }}">
                    <p class="mt-1 text-xs text-stone-500">Buyers see "Ready in X days". Leave empty if it is ready now.</p>
                    @error('lead_time_days') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="sample_price" class="label">Sample price for one unit (Le)</label>
                    <input id="sample_price" name="sample_price" type="number" step="0.01" min="0.01" inputmode="decimal" class="input" value="{{ old('sample_price', $item->sample_price) }}">
                    <p class="mt-1 text-xs text-stone-500">Buyers can order one sample before a bulk order. WiBuy gives them the sample price back as a coupon on their bulk order.</p>
                    @error('sample_price') <p class="field-error">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <div class="card space-y-3 p-5">
                <h2 class="font-bold">Pictures ({{ $images->count() }}/{{ $maxImages }})</h2>
                @if ($images->isNotEmpty())
                    <div class="grid grid-cols-3 gap-2">
                        @foreach ($images as $image)
                            <label class="block text-center text-xs">
                                @if ($image->isReady())
                                    <img src="{{ $image->thumb_url }}" alt="" class="aspect-square w-full rounded-lg object-cover">
                                @else
                                    <span class="flex aspect-square w-full items-center justify-center rounded-lg bg-stone-100 text-stone-500">{{ $image->status === 'failed' ? 'Failed' : 'Processing…' }}</span>
                                @endif
                                <input type="checkbox" name="remove_images[]" value="{{ $image->id }}" class="mt-1 accent-red-600"> Remove
                            </label>
                        @endforeach
                    </div>
                @endif
                @if ($images->count() < $maxImages)
                    <input name="images[]" type="file" multiple accept="image/jpeg,image/png,image/webp" class="input" aria-label="Add pictures">
                    <p class="text-xs text-stone-500">JPG, PNG or WebP. We resize them so they load fast on phones.</p>
                @endif
                @error('images') <p class="field-error">{{ $message }}</p> @enderror
                @error('images.*') <p class="field-error">{{ $message }}</p> @enderror
            </div>

            <div class="card space-y-3 p-5">
                <h2 class="font-bold">Show to buyers?</h2>
                <label class="choice"><input type="radio" name="status" value="active" class="size-5 accent-brand-700" @checked(old('status', $item->status?->value ?? 'draft') === 'active')> Yes, put it live</label>
                <label class="choice"><input type="radio" name="status" value="draft" class="size-5 accent-brand-700" @checked(old('status', $item->status?->value ?? 'draft') !== 'active')> No, keep it as a draft</label>
                @error('status') <p class="field-error">{{ $message }}</p> @enderror
                <button class="btn btn-primary">Save product</button>
            </div>

            @if ($item->exists)
                <form method="POST" action="{{ route('seller.products.destroy', $item) }}" onsubmit="return confirm('Delete this product?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-danger btn-sm w-full">Delete product</button>
                </form>
            @endif
        </div>
    </form>
@endsection
