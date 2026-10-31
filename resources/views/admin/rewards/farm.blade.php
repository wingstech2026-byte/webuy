@extends('admin.layout')

@section('admin')
    <h1 class="mb-3 text-2xl font-extrabold">WiBuy Farm</h1>
    @include('admin.rewards._nav')

    <div class="mb-6 grid gap-3 sm:grid-cols-4">
        <div class="card p-4"><p class="text-sm text-stone-500">Plants growing</p><p class="text-xl font-extrabold">{{ $farms['count'] }}</p></div>
        <div class="card p-4"><p class="text-sm text-stone-500">Harvests</p><p class="text-xl font-extrabold">{{ $farms['harvests'] }}</p></div>
        <div class="card p-4"><p class="text-sm text-stone-500">Prize cost this month</p><p class="text-xl font-extrabold">{{ money($cost['month']) }}</p><p class="text-xs text-stone-500">{{ money($cost['total']) }} in total</p></div>
        <div class="card p-4"><p class="text-sm text-stone-500">Gifts to deliver</p><p class="text-xl font-extrabold">{{ $cost['to_deliver'] }}</p></div>
    </div>
    <p class="mb-4 text-sm text-stone-500">Growth speed: water needed for a full plant is in Rewards and limits; water per mission is below.</p>

    <h2 class="mb-2 text-lg font-extrabold">Missions</h2>
    @foreach ($missions as $mission)
        <form method="POST" action="{{ route('admin.rewards.farm.mission', $mission) }}" class="card mb-2 grid gap-2 p-3 sm:grid-cols-6 sm:items-end">
            @csrf @method('PUT')
            <div class="sm:col-span-2"><label class="label">{{ $mission->key }}</label><input name="label" required maxlength="80" class="input" value="{{ $mission->label }}"></div>
            <div><label class="label">Water</label><input name="water" type="number" min="0" required class="input" value="{{ $mission->water }}"></div>
            <div><label class="label">Times a day</label><input name="daily_limit" type="number" min="0" required class="input" value="{{ $mission->daily_limit }}"></div>
            <div><label class="choice"><input type="checkbox" name="is_active" value="1" class="size-5 accent-brand-700" @checked($mission->is_active)> On</label></div>
            <div><button class="btn btn-primary btn-sm">Save</button></div>
        </form>
    @endforeach

    <h2 class="mb-2 mt-6 text-lg font-extrabold">Prizes for a fully grown plant</h2>
    <div class="card mb-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Plant</th><th class="th">Prize</th><th class="th text-right">Cost each</th><th class="th"></th></tr></thead>
            <tbody>
                @forelse ($rewards as $reward)
                    <tr><td class="td">{{ \App\Models\Farm::PLANTS[$reward->plant] ?? $reward->plant }}</td><td class="td">{{ $reward->label }} ({{ $reward->type === 'gift' ? $reward->gift_description : money($reward->coupon_amount).' coupon' }})</td><td class="td text-right">{{ money($reward->cost) }}</td><td class="td">{{ $reward->is_active ? 'Current' : 'Old' }}</td></tr>
                @empty
                    <tr><td class="td" colspan="4">No prizes yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <form method="POST" action="{{ route('admin.rewards.farm.reward') }}" class="card mb-6 grid max-w-3xl gap-3 p-4 sm:grid-cols-3">
        @csrf
        <div><label class="label" for="plant">Plant</label><select id="plant" name="plant" class="input">@foreach (\App\Models\Farm::PLANTS as $key => $label) <option value="{{ $key }}">{{ $label }}</option> @endforeach</select></div>
        <div class="sm:col-span-2"><label class="label" for="label">Prize name</label><input id="label" name="label" required maxlength="80" class="input" value="{{ old('label') }}" placeholder="Free 1kg bag of rice"></div>
        <div><label class="label" for="type">Type</label><select id="type" name="type" class="input"><option value="gift">Real gift with next order</option><option value="coupon">Large coupon</option></select></div>
        <div class="sm:col-span-2"><label class="label" for="gift_description">Gift (what we deliver)</label><input id="gift_description" name="gift_description" maxlength="200" class="input" value="{{ old('gift_description') }}" placeholder="A 1kg bag of rice delivered with your next order"></div>
        <div><label class="label" for="coupon_amount">Coupon (Le)</label><input id="coupon_amount" name="coupon_amount" type="number" step="0.01" min="0" class="input" value="{{ old('coupon_amount') }}"></div>
        <div><label class="label" for="coupon_min_spend">Coupon min. order (Le)</label><input id="coupon_min_spend" name="coupon_min_spend" type="number" step="0.01" min="0" class="input" value="{{ old('coupon_min_spend', 0) }}"></div>
        <div><label class="label" for="cost">Cost to WiBuy (Le)</label><input id="cost" name="cost" type="number" step="0.01" min="0" required class="input" value="{{ old('cost') }}"></div>
        <div class="sm:col-span-3">@foreach (['label', 'gift_description', 'coupon_amount', 'cost'] as $f) @error($f) <p class="field-error">{{ $message }}</p> @enderror @endforeach <button class="btn btn-primary">Save prize</button></div>
    </form>

    <h2 class="mb-2 text-lg font-extrabold">Prizes won</h2>
    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Winner</th><th class="th">Prize</th><th class="th">Order</th><th class="th text-right">Cost</th><th class="th">Status</th><th class="th"></th></tr></thead>
            <tbody>
                @forelse ($claims as $claim)
                    <tr>
                        <td class="td">{{ $claim->user->name }} ({{ phone_display($claim->user->phone) }})</td>
                        <td class="td">{{ $claim->description }}</td>
                        <td class="td">@if ($claim->order) <a class="text-brand-700" href="{{ route('admin.orders.show', $claim->order) }}">{{ $claim->order->number }}</a> @else &ndash; @endif</td>
                        <td class="td text-right">{{ money($claim->cost) }}</td>
                        <td class="td">{{ $claim->status === 'pending' ? 'waiting for an order' : $claim->status }}</td>
                        <td class="td">@if ($claim->type === 'gift' && $claim->status !== 'fulfilled') <form method="POST" action="{{ route('admin.rewards.farm.fulfil', $claim) }}">@csrf<button class="btn btn-primary btn-sm">Delivered</button></form> @endif</td>
                    </tr>
                @empty
                    <tr><td class="td" colspan="6">No prizes won yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $claims->links() }}</div>
@endsection
