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
                    <button class="btn btn-primary">Approve shop</button>
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
