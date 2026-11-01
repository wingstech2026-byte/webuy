@extends('admin.layout')

@php use App\Enums\SellerStatus; @endphp

@section('admin')
    <a href="{{ route('admin.sellers.index', ['status' => $seller->status->value]) }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; Sellers</a>

    <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div class="space-y-4">
            <div class="card flex flex-wrap items-center gap-4 p-5">
                <x-shop-logo :seller="$seller" class="size-20 shrink-0 rounded-full text-3xl" />
                <div>
                    <h1 class="text-2xl font-extrabold">{{ $seller->shop_name }}</h1>
                    <p><span class="badge {{ $seller->status->badge() }}">{{ $seller->status->label() }}</span>
                        @if ($seller->is_verified) <span class="badge bg-brand-100 text-brand-800">✓ Verified</span> @endif</p>
                    @if ($seller->isApproved()) <a class="text-sm text-brand-700" href="{{ route('shops.show', $seller) }}">Public shop page &rarr;</a> @endif
                </div>
            </div>

            <dl class="card grid gap-3 p-5 text-sm sm:grid-cols-2">
                <div><dt class="font-bold text-stone-500">Owner</dt><dd>{{ $seller->user->name }} &middot; {{ $seller->user->display_phone }}</dd></div>
                <div><dt class="font-bold text-stone-500">Location</dt><dd>{{ $seller->location }}</dd></div>
                <div class="sm:col-span-2"><dt class="font-bold text-stone-500">Pickup address</dt><dd>{{ $seller->pickup_address }}</dd></div>
                <div><dt class="font-bold text-stone-500">Payouts to</dt><dd>{{ $seller->payout_operator->label() }} {{ phone_display($seller->payout_phone) }}</dd></div>
                <div><dt class="font-bold text-stone-500">Business registration</dt><dd>{{ $seller->business_registration_no ?: '—' }}</dd></div>
                <div><dt class="font-bold text-stone-500">Delivery</dt><dd>{{ $seller->offers_delivery ? $seller->deliveryAreas->count().' district(s)' : 'No' }} &middot; Pickup: {{ $seller->offers_pickup ? 'yes' : 'no' }}</dd></div>
                <div><dt class="font-bold text-stone-500">Products / orders</dt><dd>{{ $productCount }} / {{ $orderCount }}</dd></div>
                @if ($seller->description) <div class="sm:col-span-2"><dt class="font-bold text-stone-500">About</dt><dd>{{ $seller->description }}</dd></div> @endif
                @if ($seller->rejection_reason) <div class="sm:col-span-2"><dt class="font-bold text-stone-500">Rejection reason</dt><dd>{{ $seller->rejection_reason }}</dd></div> @endif
            </dl>

            {{-- The owner's national ID card: check the photo matches the number and the owner's name. --}}
            <section class="card p-5" aria-labelledby="owner-id">
                <h2 id="owner-id" class="mb-2 font-extrabold">🪪 Owner's national ID</h2>
                @if ($seller->hasOwnerIdentity())
                    <p class="text-sm">ID number (serial number on the card): <strong class="font-mono text-base">{{ $seller->owner_id_number }}</strong></p>
                    <p class="text-xs text-stone-500">Sent {{ $seller->owner_id_submitted_at?->format('j M Y, g:ia') }}. Check that the name on the card is {{ $seller->user->name }}.</p>
                    @if ($sameIdElsewhere->isNotEmpty())
                        <p class="mt-2 rounded-lg bg-amber-50 p-2 text-sm text-amber-900">⚠️ The same ID number was sent for:
                            @foreach ($sameIdElsewhere as $other) <a class="font-semibold underline" href="{{ route('admin.sellers.show', $other) }}">{{ $other->shop_name }}</a> ({{ $other->status->label() }})@if (! $loop->last), @endif @endforeach
                        </p>
                    @endif
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        @foreach (array_filter(['front' => $seller->owner_id_front_path, 'back' => $seller->owner_id_back_path]) as $side => $path)
                            <a href="{{ route('admin.sellers.id-card', [$seller, $side]) }}" target="_blank" rel="noopener" class="block">
                                <img src="{{ route('admin.sellers.id-card', [$seller, $side]) }}" alt="ID card, {{ $side }}" class="w-full rounded-lg border border-stone-200 object-contain">
                                <span class="text-xs text-stone-500">{{ ucfirst($side) }} &middot; open full size</span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="rounded-lg bg-red-50 p-3 text-sm text-red-800">The owner has not sent their national ID card yet. The shop cannot be approved until they do (they add it on the "Sell on WiBuy" page).</p>
                @endif
            </section>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="card p-4"><p class="text-sm text-stone-500">Pending</p><p class="font-extrabold">{{ money($money['pending']) }}</p></div>
                <div class="card p-4"><p class="text-sm text-stone-500">Available</p><p class="font-extrabold">{{ money($money['available']) }}</p></div>
                <div class="card p-4"><p class="text-sm text-stone-500">Paid out</p><p class="font-extrabold">{{ money($money['paid_out']) }}</p></div>
            </div>
        </div>

        <aside class="space-y-3">
            @if ($seller->status !== SellerStatus::Approved)
                <form method="POST" action="{{ route('admin.sellers.approve', $seller) }}" class="card space-y-2 p-4">
                    @csrf
                    <label class="choice"><input type="checkbox" name="verified" value="1" class="size-5 accent-brand-700"> Also give the Verified badge</label>
                    <button class="btn btn-primary" @disabled(! $seller->hasOwnerIdentity())>Approve shop</button>
                    @unless ($seller->hasOwnerIdentity()) <p class="text-xs text-red-700">Waiting for the owner's national ID.</p> @endunless
                </form>
            @endif
            @if ($seller->status === SellerStatus::Pending)
                <form method="POST" action="{{ route('admin.sellers.reject', $seller) }}" class="card space-y-2 p-4">
                    @csrf
                    <label for="reason" class="label">Reason (sent by SMS)</label>
                    <input id="reason" name="reason" maxlength="200" class="input" placeholder="e.g. Please add your pickup address">
                    <button class="btn btn-danger">Reject</button>
                </form>
            @endif
            @if ($seller->status === SellerStatus::Approved)
                <form method="POST" action="{{ route('admin.sellers.verify', $seller) }}" class="card p-4">@csrf
                    <button class="btn btn-outline">{{ $seller->is_verified ? 'Remove Verified badge' : 'Give Verified badge' }}</button>
                </form>
                <form method="POST" action="{{ route('admin.sellers.suspend', $seller) }}" class="card p-4" onsubmit="return confirm('Suspend this shop? Its products are hidden.')">@csrf
                    <button class="btn btn-danger">Suspend shop</button>
                </form>
            @endif
            <form method="POST" action="{{ route('admin.sellers.commission', $seller) }}" class="card space-y-2 p-4">
                @csrf
                <label for="commission_percent" class="label">Commission for this seller (%)</label>
                <input id="commission_percent" name="commission_percent" type="number" step="0.01" min="0" max="50" class="input" value="{{ $seller->commission_percent }}" placeholder="Site default: {{ \App\Services\SettingsService::commissionPercent() }}%">
                @error('commission_percent') <p class="field-error">{{ $message }}</p> @enderror
                <button class="btn btn-outline btn-sm">Save</button>
            </form>
        </aside>
    </div>
@endsection
