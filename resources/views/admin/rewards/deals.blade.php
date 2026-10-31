@extends('admin.layout')

@section('admin')
    <h1 class="mb-3 text-2xl font-extrabold">Lightning deals</h1>
    @include('admin.rewards._nav')

    <div class="mb-4 flex flex-wrap gap-2">
        <form method="POST" action="{{ route('admin.rewards.deals.rotate') }}">@csrf <input type="hidden" name="day" value="tomorrow">
            <button class="btn btn-outline btn-sm">Fill tomorrow automatically</button>
        </form>
        <form method="POST" action="{{ route('admin.rewards.deals.rotate') }}">@csrf <input type="hidden" name="day" value="today">
            <button class="btn btn-outline btn-sm">Fill today automatically</button>
        </form>
    </div>
    <p class="mb-4 text-sm text-stone-500">Automatic deals use products marked as "deal", taking turns every day. Length, number per day and discount are in Rewards and limits. The scheduler fills each next day by itself.</p>

    <div class="card mb-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Product</th><th class="th">When</th><th class="th text-right">Deal price</th><th class="th text-right">Sold</th><th class="th text-right">Reminders</th><th class="th"></th></tr></thead>
            <tbody>
                @forelse ($deals as $deal)
                    <tr>
                        <td class="td">{{ $deal->product->title }} <span class="badge bg-stone-100">{{ $deal->state }}</span></td>
                        <td class="td">{{ $deal->starts_at->format('j M g:ia') }} &ndash; {{ $deal->ends_at->format('g:ia') }}</td>
                        <td class="td text-right">{{ money($deal->deal_price) }} <span class="text-stone-400 line-through">{{ money($deal->product->price) }}</span></td>
                        <td class="td text-right">{{ $deal->sold_count }}{{ $deal->quantity_limit ? ' / '.$deal->quantity_limit : '' }}</td>
                        <td class="td text-right">{{ $deal->reminders_count }}</td>
                        <td class="td"><form method="POST" action="{{ route('admin.rewards.deals.destroy', $deal) }}" onsubmit="return confirm('Remove this deal?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm">Remove</button></form></td>
                    </tr>
                @empty
                    <tr><td class="td" colspan="6">No lightning deals.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <h2 class="mb-2 text-lg font-extrabold">Schedule a deal</h2>
    <form method="POST" action="{{ route('admin.rewards.deals.store') }}" class="card grid max-w-3xl gap-3 p-4 sm:grid-cols-3">
        @csrf
        <div class="sm:col-span-3"><label class="label" for="product">Product link name (the part after /p/)</label><input id="product" name="product" required class="input" value="{{ old('product') }}" placeholder="local-parboiled-rice-50kg-bag">@error('product') <p class="field-error">{{ $message }}</p> @enderror</div>
        <div><label class="label" for="deal_price">Deal price (Le)</label><input id="deal_price" name="deal_price" type="number" step="0.01" min="0.01" required class="input" value="{{ old('deal_price') }}">@error('deal_price') <p class="field-error">{{ $message }}</p> @enderror</div>
        <div><label class="label" for="starts_at">Starts</label><input id="starts_at" name="starts_at" type="datetime-local" required class="input" value="{{ old('starts_at') }}"></div>
        <div><label class="label" for="hours">Hours</label><input id="hours" name="hours" type="number" min="1" max="48" required class="input" value="{{ old('hours', 2) }}"></div>
        <div><label class="label" for="max_per_order">Deal price up to (per order)</label><input id="max_per_order" name="max_per_order" type="number" min="1" required class="input" value="{{ old('max_per_order', 5) }}"></div>
        <div><label class="label" for="quantity_limit">Units at this price (optional)</label><input id="quantity_limit" name="quantity_limit" type="number" min="1" class="input" value="{{ old('quantity_limit') }}"></div>
        <div class="sm:col-span-3"><button class="btn btn-primary">Schedule</button></div>
    </form>
@endsection
