@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Group buying</h1>

    <div class="card mb-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Offer</th><th class="th">Shop</th><th class="th text-right">Price</th><th class="th text-right">Groups (ok / failed / open)</th><th class="th text-right">Sold</th><th class="th"></th></tr></thead>
            <tbody>
                @forelse ($campaigns as $c)
                    @php($s = $stats[$c->id])
                    <tr>
                        <td class="td">{{ $c->product->title }} &middot; {{ $c->min_group_size }} in {{ $c->hours }} h</td>
                        <td class="td">{{ $c->seller->shop_name }}</td>
                        <td class="td text-right">{{ money($c->group_price) }} <span class="text-stone-400 line-through">{{ money($c->product->price) }}</span></td>
                        <td class="td text-right">{{ $s['succeeded'] }} / {{ $s['failed'] }} / {{ $s['open'] }}</td>
                        <td class="td text-right">{{ $s['units_sold'] }} &middot; {{ money($s['revenue']) }}</td>
                        <td class="td"><form method="POST" action="{{ route('admin.group-buys.toggle', $c) }}">@csrf<button class="btn btn-sm {{ $c->is_active ? 'btn-outline' : 'btn-primary' }}">{{ $c->is_active ? 'Switch off' : 'Switch on' }}</button></form></td>
                    </tr>
                @empty
                    <tr><td class="td" colspan="6">No group offers yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $campaigns->links() }}

    <h2 class="mb-2 mt-6 text-lg font-extrabold">Latest groups</h2>
    @foreach ($recent as $g)
        <div class="card mb-2 flex justify-between p-3 text-sm">
            <span>{{ $g->campaign->product->title }} &middot; leader {{ $g->leader->name }} &middot; {{ $g->paid_count }}/{{ $g->campaign->min_group_size }} paid &middot; ends {{ $g->expires_at->format('j M g:ia') }}</span>
            <span class="badge bg-stone-100">{{ $g->status }}</span>
        </div>
    @endforeach
@endsection
