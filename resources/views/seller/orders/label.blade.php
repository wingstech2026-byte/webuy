<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Label {{ $order->number }} · {{ config('wibuy.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 16px; color: #111; background: #fff; }
        .label { width: 100mm; border: 2px dashed #444; padding: 6mm; box-sizing: border-box; }
        .brand { font-weight: 900; font-size: 20px; color: #15803d; }
        .number { font: 900 26px/1.1 ui-monospace, monospace; letter-spacing: 1px; margin: 4px 0; }
        .to { font-size: 15px; margin-top: 6px; }
        .small { font-size: 12px; color: #444; }
        svg { width: 100%; height: auto; display: block; margin: 6px 0; }
        @media print { .no-print { display: none; } body { margin: 0; } .label { border: 0; } }
    </style>
</head>
<body>
    <div class="label">
        <div class="brand">{{ config('wibuy.name') }}</div>
        <div class="number">{{ $order->number }}</div>
        {!! \App\Support\Code128::svg($order->number) !!}
        <div class="to">
            @if ($order->viaStation())
                <strong>Drop at:</strong> WiBuy station {{ $order->dropStation?->name }}<br>
                @if ($order->collectStation)
                    <strong>Buyer collects at:</strong> {{ $order->collectStation->name }} ({{ $order->collectStation->district }})
                @else
                    <strong>Deliver to:</strong> {{ $order->recipient_name }}, {{ $order->district }}
                @endif
            @elseif ($order->isPickup())
                <strong>Buyer collects at the shop:</strong> {{ $order->recipient_name }}
            @else
                <strong>Deliver to:</strong> {{ $order->recipient_name }}, {{ $order->district }}
            @endif
        </div>
        <div class="small">From {{ $order->seller->shop_name }} &middot; {{ $order->items->sum('quantity') }} item(s) &middot; {{ $order->paid_at?->format('j M Y') }}</div>
    </div>
    <p class="no-print"><button onclick="window.print()">🖨 Print</button> &nbsp; <a href="{{ route('seller.orders.show', $order) }}">Back to the order</a></p>
</body>
</html>
