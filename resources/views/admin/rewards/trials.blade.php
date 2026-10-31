@extends('admin.layout')

@section('admin')
    <h1 class="mb-3 text-2xl font-extrabold">Free trials</h1>
    @include('admin.rewards._nav')
    <p class="mb-4 text-sm text-stone-500">Sellers create free trials in their dashboard (Promotions). Selected testers get a coupon so one unit costs the trial price, and must post an honest review with photos. Their reviews show a "Free trial" label.</p>

    @forelse ($campaigns as $campaign)
        <div class="card mb-3 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="font-bold">{{ $campaign->product->title }}</p>
                    <p class="text-sm text-stone-600">{{ $campaign->seller->shop_name }} &middot; trial price {{ money($campaign->trial_price) }} (normal {{ money($campaign->product->price) }}) &middot; {{ $campaign->selectedCount() }}/{{ $campaign->slots }} places &middot; apply until {{ $campaign->apply_until->format('j M') }}</p>
                </div>
                @if ($campaign->isOpen())
                    <form method="POST" action="{{ route('admin.rewards.trials.close', $campaign) }}">@csrf<button class="btn btn-outline btn-sm">Close</button></form>
                @else
                    <span class="badge bg-stone-100">closed</span>
                @endif
            </div>
            @include('admin.rewards._trial-applications', ['campaign' => $campaign, 'selectRoute' => 'admin.rewards.trials.select', 'rejectRoute' => 'admin.rewards.trials.reject'])
        </div>
    @empty
        <x-empty title="No free trials">Sellers have not created any free trials yet.</x-empty>
    @endforelse
    {{ $campaigns->links() }}
@endsection
