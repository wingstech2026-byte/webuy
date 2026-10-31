@extends('admin.layout')

@section('admin')
    <h1 class="mb-3 text-2xl font-extrabold">Cut the price</h1>
    @include('admin.rewards._nav')
    <p class="mb-4 text-sm text-stone-500">Each new friend cuts a random amount between the smallest and largest cut until the lowest price. Then the buyer gets a coupon for the difference on one unit (WiBuy pays it; the seller gets the normal price). One cut per friend per product; self-cuts and many accounts on one phone are blocked.</p>

    <div class="card mb-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Product</th><th class="th text-right">Price &rarr; lowest</th><th class="th text-right">Cut size</th><th class="th text-right">Hours</th><th class="th text-right">Started / reached</th><th class="th"></th></tr></thead>
            <tbody>
                @forelse ($campaigns as $c)
                    <tr>
                        <td class="td">{{ $c->product->title }}</td>
                        <td class="td text-right">{{ money($c->product->price) }} &rarr; {{ money($c->floor_price) }}</td>
                        <td class="td text-right">{{ money($c->min_cut) }}&ndash;{{ money($c->max_cut) }}</td>
                        <td class="td text-right">{{ $c->hours }}</td>
                        <td class="td text-right">{{ $c->cuts_count }} / {{ $c->reached_count }}</td>
                        <td class="td"><form method="POST" action="{{ route('admin.rewards.cuts.toggle', $c) }}">@csrf<button class="btn btn-sm {{ $c->is_active ? 'btn-outline' : 'btn-primary' }}">{{ $c->is_active ? 'Switch off' : 'Switch on' }}</button></form></td>
                    </tr>
                @empty
                    <tr><td class="td" colspan="6">No price cuts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $campaigns->links() }}

    <h2 class="mb-2 mt-6 text-lg font-extrabold">Add a product</h2>
    @include('admin.rewards._cut-form', ['action' => route('admin.rewards.cuts.store')])
@endsection
