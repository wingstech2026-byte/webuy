@extends('layouts.app')

@section('title', 'Order '.$order->number)

@php
    use App\Enums\OrderStatus;

    // The normal path of an order, with the time each step happened.
    $steps = [
        ['Ordered and paid', $order->paid_at],
        ['Seller accepted', $order->accepted_at],
        [$order->shipped_label, $order->shipped_at],
        ['Delivered', $order->delivered_at],
        ['Completed', $order->completed_at],
    ];
    $sideNote = match ($order->status) {
        OrderStatus::Rejected => ['The seller could not fulfil this order.', $order->rejection_reason],
        OrderStatus::Cancelled => ['This order was cancelled.', $order->refund_reason],
        OrderStatus::Refunded => ['Refunded: '.money($order->amount_paid).' was sent back to your mobile money.', $order->refund_reason],
        OrderStatus::Disputed => ['You reported a problem. Your money is on hold until it is decided.', null],
        default => null,
    };
@endphp

@section('content')
    <a href="{{ route('orders.index') }}" class="mb-3 inline-block text-sm font-semibold text-brand-700">&larr; My orders</a>

    <div class="mx-auto max-w-2xl space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h1 class="text-2xl font-extrabold">Order {{ $order->number }}</h1>
                <p class="text-stone-600">from <a class="font-semibold text-brand-700" href="{{ route('shops.show', $order->seller) }}">{{ $order->seller->shop_name }}</a></p>
            </div>
            <span class="badge {{ $order->status->badge() }} text-sm">{{ $order->statusLabel() }}</span>
        </div>

        @if ($order->is_instant)
            <p class="rounded-lg bg-amber-50 p-3 text-amber-900">⚡ Instant food{{ $order->deliver_at ? ', delivery at '.$order->deliver_at->format('D j M, H:i') : '' }}.
                {{ $order->food_ready_at ? 'The food is ready: a WiBuy rider is bringing it.' : 'The shop cooks it once it accepts.' }}</p>
        @endif

        @if ($isBuyer && $order->paid_at)
            <div class="flex flex-wrap items-center gap-2">
                <form method="POST" action="{{ route('orders.buy-again', $order) }}">@csrf <button class="btn btn-primary btn-sm">🔁 Buy again</button></form>
                <a href="{{ route('orders.receipt', $order) }}" target="_blank" class="btn btn-outline btn-sm">🧾 Receipt</a>
                <form method="POST" action="{{ route('orders.repeat', $order) }}" class="flex items-center gap-1">
                    @csrf
                    <label for="every_days" class="text-sm text-stone-600">Remind me to order again</label>
                    <select id="every_days" name="every_days" class="input !w-auto !py-1 text-sm" onchange="this.form.submit()">
                        <option value="0">Never</option>
                        @foreach (\App\Services\BuyAgainService::REPEAT_DAYS as $days)
                            <option value="{{ $days }}" @selected($order->repeat?->is_active && $order->repeat->every_days === $days)>every {{ $days }} days</option>
                        @endforeach
                    </select>
                </form>
            </div>
        @endif

        @if ($sideNote)
            <div class="card border-amber-300 bg-amber-50 p-4 text-amber-900">
                <p class="font-bold">{{ $sideNote[0] }}</p>
                @if ($sideNote[1]) <p class="text-sm">{{ $sideNote[1] }}</p> @endif
                @if (in_array($order->status, [OrderStatus::Rejected, OrderStatus::Cancelled], true) && $order->paid_at)
                    <p class="mt-1 text-sm">Your refund of {{ money($order->amount_paid) }} is on the way.</p>
                @endif
            </div>
        @endif

        @if ($order->status === OrderStatus::Paid && ! $order->groupParticipant()->exists() && ! $order->milestones()->exists())
            <form method="POST" action="{{ route('orders.cancel', $order) }}" class="card flex flex-wrap items-center justify-between gap-2 p-4" onsubmit="return confirm('Cancel this order? Your money comes back to your mobile money.')">
                @csrf
                <p class="text-sm">Changed your mind? You can cancel until the shop accepts. You get all your money back.</p>
                <button class="btn btn-outline btn-sm">Cancel my order</button>
            </form>
        @endif
        {{-- Timeline --}}
        <ol class="card space-y-3 p-4" aria-label="Order progress">
            @foreach ($steps as [$label, $at])
                <li class="flex items-center gap-3">
                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-bold {{ $at ? 'bg-brand-600 text-white' : 'bg-stone-200 text-stone-500' }}" aria-hidden="true">{{ $at ? '✓' : $loop->iteration }}</span>
                    <span class="flex-1 {{ $at ? 'font-semibold' : 'text-stone-500' }}">{{ $label }}</span>
                    <span class="text-xs text-stone-500">{{ $at?->format('j M, g:ia') }}</span>
                </li>
            @endforeach
        </ol>

        @if ($rider = $order->riderDelivery())
            <div class="card p-4" aria-label="Delivery by a WiBuy rider">
                <p class="text-sm font-bold">🛵 {{ $rider['label'] }}</p>
                @if ($rider['rider_name'])
                    <p class="text-sm">Rider: <strong>{{ $rider['rider_name'] }}</strong>
                        @if ($rider['rider_phone']) &middot; <a class="font-semibold text-brand-700 underline" href="tel:+{{ $rider['rider_phone'] }}">{{ phone_display($rider['rider_phone']) }}</a> @endif</p>
                @endif
                <ol class="mt-2 flex flex-wrap gap-2 text-xs">
                    @foreach ($rider['steps'] as $step)
                        <li class="badge {{ $step['at'] ? 'bg-brand-100 text-brand-800' : 'bg-stone-100 text-stone-500' }}">{{ $step['at'] ? '✓' : '·' }} {{ $step['label'] }}</li>
                    @endforeach
                </ol>
                @if ($rider['can_rate'])
                    <form method="POST" action="{{ route('orders.rate-rider', $order) }}" class="mt-3 space-y-2 rounded-lg bg-brand-50 p-3">
                        @csrf
                        <p class="text-sm font-bold">How was {{ $rider['rider_name'] }}?</p>
                        <div class="flex flex-wrap gap-2">
                            @for ($i = 5; $i >= 1; $i--)
                                <label class="choice min-h-10 px-3 text-sm"><input type="radio" name="stars" value="{{ $i }}" required class="size-4 accent-brand-700">{{ str_repeat('★', $i) }}</label>
                            @endfor
                        </div>
                        <input name="comment" maxlength="300" class="input" placeholder="Anything to say? (optional)">
                        <button class="btn btn-primary btn-sm">Rate the rider</button>
                    </form>
                @elseif ($rider['rating'])
                    <p class="mt-2 text-sm">You gave the rider {{ str_repeat('★', $rider['rating']) }}. Thank you!</p>
                @endif
                @if (in_array($rider['stage'], [\App\Models\DeliveryJob::ASSIGNED, \App\Models\DeliveryJob::PICKED_UP], true))
                    {{-- Live: where the rider is, how far and how long. Refreshes every 20 seconds. --}}
                    <div class="mt-3" data-tracking data-url="{{ route('orders.tracking', $order) }}">
                        <p class="font-bold" data-tracking-text>
                            @if ($rider['tracking'] && $rider['tracking']['km'] !== null && $rider['tracking']['phase'] === 'to_shop')
                                The rider is {{ $rider['tracking']['km'] }} km from the shop &middot; collects your order in about {{ $rider['tracking']['minutes'] }} min
                            @elseif ($rider['tracking'] && $rider['tracking']['km'] !== null)
                                About {{ $rider['tracking']['km'] }} km away &middot; about {{ $rider['tracking']['minutes'] }} min
                            @elseif ($rider['tracking'])
                                The rider is on the way.
                            @else
                                Waiting for the rider's location…
                            @endif
                        </p>
                        <div class="mt-2 h-56 overflow-hidden rounded-lg bg-stone-100" data-tracking-map></div>
                        <p class="mt-1 text-xs text-stone-500" data-tracking-updated>Distance and time are estimates.</p>
                    </div>
                    <p class="mt-2 text-xs text-stone-600">Give the rider the 4-digit code from our SMS only when you have your goods.</p>
                @endif
            </div>
            @if (in_array($rider['stage'], [\App\Models\DeliveryJob::ASSIGNED, \App\Models\DeliveryJob::PICKED_UP], true))
                @push('head')
                    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
                    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
                @endpush
                <script>
                    (() => {
                        const box = document.querySelector('[data-tracking]');
                        const text = box.querySelector('[data-tracking-text]');
                        const updated = box.querySelector('[data-tracking-updated]');
                        let map, riderMark, homeMark;

                        const show = (t) => {
                            if (!t) { text.textContent = "Waiting for the rider's location…"; return; }
                            const toShop = t.phase === 'to_shop';
                            text.textContent = t.km === null ? 'The rider is on the way.'
                                : (toShop ? `The rider is ${t.km} km from the shop · collects your order in about ${t.minutes} min` : `About ${t.km} km away · about ${t.minutes} min${t.to_station ? ' to ' + t.to_station : ''}`);
                            updated.textContent = (t.live ? 'Live · updated ' : 'Last seen ') + new Date(t.updated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + '. Distance and time are estimates.';
                            if (!window.L) return;
                            const r = [t.rider.lat, t.rider.lng];
                            if (!map) {
                                map = L.map(box.querySelector('[data-tracking-map]'), { zoomControl: false }).setView(r, 14);
                                L.tileLayer(@json(config('services.maps.tiles')), { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
                                riderMark = L.marker(r, { title: 'Rider' }).addTo(map).bindTooltip('🛵 Rider', { permanent: true });
                            }
                            const label = toShop ? '🏪 Shop' : (t.to_station ? '📦 Station' : '🏠 You');
                            if (t.destination && (!homeMark || homeMark.label !== label)) {
                                if (homeMark) homeMark.remove();
                                homeMark = L.circleMarker([t.destination.lat, t.destination.lng], { radius: 8, color: toShop ? '#b45309' : '#15803d' }).addTo(map).bindTooltip(label);
                                homeMark.label = label;
                            }
                            riderMark.setLatLng(r);
                            if (homeMark) map.fitBounds(L.latLngBounds([r, homeMark.getLatLng()]).pad(0.3)); else map.setView(r);
                        };

                        const poll = () => fetch(box.dataset.url, { headers: { Accept: 'application/json' } })
                            .then((res) => res.ok ? res.json() : null)
                            .then((json) => {
                                if (!json) return;
                                if (!json.rider_delivery || json.rider_delivery.stage !== @json($rider['stage'])) { location.reload(); return; } // collected or delivered
                                show(json.rider_delivery.tracking);
                            });

                        show(@json($rider['tracking']));
                        setInterval(poll, 20000);
                    })();
                </script>
            @endif
        @endif

        @if ($order->delivery_note)
            <div class="card p-4"><p class="text-sm font-bold">Note from the seller</p><p>{{ $order->delivery_note }}</p></div>
        @endif

        {{-- Buyer actions --}}
        @if ($isBuyer)
            @if (in_array($order->status, [OrderStatus::Shipped, OrderStatus::Delivered], true))
                <div class="card space-y-3 p-4">
                    <p class="font-bold">Did you get your order?</p>
                    <form method="POST" action="{{ route('orders.confirm', $order) }}" onsubmit="return confirm('Confirm you received everything? The seller will then be paid.')">
                        @csrf
                        <button class="btn btn-primary">Yes, confirm delivery</button>
                    </form>
                    <p class="text-xs text-stone-500">If you do nothing, the order completes by itself {{ config('wibuy.auto_complete_days') }} days after it was {{ $order->delivered_at ? 'delivered' : 'shipped' }}.</p>
                </div>
            @endif

            @if ($order->deliveryJob && in_array($order->deliveryJob->status, ['assigned', 'picked_up'], true))
                <div class="card space-y-1 p-4 text-sm">
                    <p class="font-bold">🛵 {{ $order->deliveryJob->statusLabel() }}</p>
                    <p>Rider: {{ $order->deliveryJob->rider?->name }} &middot; {{ $order->deliveryJob->rider?->display_phone }}</p>
                    @if ($order->deliveryJob->status === 'picked_up')
                        <p class="text-stone-600">We sent you a 4-digit code by SMS. Give it to the rider only when you have your goods.</p>
                    @endif
                </div>
            @endif

            @if ($order->returnRequest)
                @include('returns._panel', ['forSeller' => false])
            @elseif ($order->status === OrderStatus::Delivered)
                <a href="{{ route('returns.create', $order) }}" class="btn btn-outline">↩️ Return items</a>
            @endif

            @if (in_array($order->status, OrderStatus::disputable(), true))
                <a href="{{ route('disputes.create', $order) }}" class="btn btn-danger">Report a problem</a>
            @endif

            @if ($order->disputes->isNotEmpty())
                <a href="{{ route('disputes.show', $order->disputes->first()) }}" class="btn btn-outline">See the problem report</a>
            @endif

            @if ($canReview)
                <form method="POST" action="{{ route('orders.review', $order) }}" enctype="multipart/form-data" class="card space-y-4 p-4">
                    @csrf
                    <h2 class="text-lg font-extrabold">Leave a review</h2>
                    @error('seller_rating') <p class="field-error">{{ $message }}</p> @enderror

                    @unless ($order->sellerReview)
                        <fieldset>
                            <legend class="label">How was {{ $order->seller->shop_name }}?</legend>
                            <div class="flex flex-wrap gap-2">
                                @for ($i = 5; $i >= 1; $i--)
                                    <label class="choice min-h-12 px-3"><input type="radio" name="seller_rating" value="{{ $i }}" class="size-5 accent-brand-700">{{ str_repeat('★', $i) }}</label>
                                @endfor
                            </div>
                            <textarea name="seller_comment" rows="2" maxlength="1000" class="input mt-2" placeholder="Anything to say about the seller? (optional)"></textarea>
                        </fieldset>
                    @endunless

                    @foreach ($order->items as $item)
                        @continue($item->review)
                        <fieldset>
                            <legend class="label">{{ $item->title }}</legend>
                            <div class="flex flex-wrap gap-2">
                                @for ($i = 5; $i >= 1; $i--)
                                    <label class="choice min-h-12 px-3"><input type="radio" name="items[{{ $item->id }}][rating]" value="{{ $i }}" class="size-5 accent-brand-700">{{ str_repeat('★', $i) }}</label>
                                @endfor
                            </div>
                            <textarea name="items[{{ $item->id }}][comment]" rows="2" maxlength="1000" class="input mt-2" placeholder="What did you think? (optional)"></textarea>
                            <label for="photos-{{ $item->id }}" class="mt-2 block text-sm font-semibold text-stone-600">📷 Photos of what you got (optional, up to 4)</label>
                            <input id="photos-{{ $item->id }}" type="file" name="items[{{ $item->id }}][photos][]" accept="image/*" multiple class="mt-1 block w-full text-sm">
                            @error("items.{$item->id}.photos*") <p class="field-error">{{ $message }}</p> @enderror
                        </fieldset>
                    @endforeach

                    <button class="btn btn-primary">Send review</button>
                </form>
            @elseif ($order->status === OrderStatus::Completed)
                <p class="text-center text-sm text-stone-500">Thank you for your review.</p>
            @endif
        @endif

        {{-- Items and money --}}
        <div class="card divide-y divide-stone-100 text-sm">
            @foreach ($order->items as $item)
                <div class="flex items-center gap-3 p-4">
                    @if ($item->thumb_url)
                        <img src="{{ $item->thumb_url }}" alt="" loading="lazy" class="size-12 shrink-0 rounded-lg object-cover">
                    @endif
                    <span class="flex-1">{{ $item->quantity }} &times; {{ $item->display_title }} <span class="text-stone-500">@ {{ money($item->unit_price) }}</span></span>
                    <strong>{{ money($item->line_total) }}</strong>
                </div>
            @endforeach
            <div class="flex justify-between p-4"><span>Delivery</span><span>{{ money($order->delivery_fee) }}</span></div>
            @if (! \App\Support\Money::isZero($order->discount))
                <div class="flex justify-between p-4 text-brand-700"><span>Coupon and WiBuy Pass savings</span><span>-{{ money($order->discount) }}</span></div>
            @endif
            <div class="flex justify-between p-4 text-base"><strong>Total paid</strong><strong>{{ money($order->amount_paid) }}</strong></div>
            @if ($order->gift_note)
                <div class="p-4 text-brand-700">🎁 {{ $order->gift_note }}</div>
            @endif
        </div>

        <div class="card space-y-1 p-4 text-sm">
            @if ($order->isPickup())
                <p class="font-bold">Pickup from the seller</p>
                <p>{{ $order->seller->pickup_address }}, {{ $order->seller->location }}</p>
            @elseif ($order->delivery_method === \App\Enums\DeliveryMethod::Station && $order->collectStation)
                <p class="font-bold">📦 Collect at WiBuy station</p>
                <p>{{ $order->collectStation->label() }}</p>
                <p class="text-stone-600">{{ $order->at_collect_station_at ? 'It is there now. Show the code from our SMS to collect it.' : 'We text you a code when it arrives at the station.' }}</p>
            @else
                <p class="font-bold">Delivery to</p>
                <p>{{ $order->recipient_name }}, {{ phone_display($order->recipient_phone) }}</p>
                <p>{{ $order->address }}, {{ $order->district }}</p>
            @endif
            @if ($order->buyer_note) <p class="text-stone-500">Your note: {{ $order->buyer_note }}</p> @endif
        </div>
    </div>
@endsection
