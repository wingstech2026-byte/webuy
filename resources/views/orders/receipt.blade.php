<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $order->number }} · {{ config('wibuy.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 16px; color: #111; background: #fff; }
        .receipt { max-width: 150mm; margin: 0 auto; border: 1px solid #ccc; padding: 8mm; box-sizing: border-box; }
        .brand { font-weight: 900; font-size: 22px; color: #15803d; }
        h1 { font-size: 18px; margin: 4px 0 12px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #eee; text-align: left; }
        .r { text-align: right; }
        .total td { font-weight: 800; border-top: 2px solid #111; }
        .small { font-size: 12px; color: #555; }
        .grid { display: flex; flex-wrap: wrap; gap: 16px; justify-content: space-between; margin-bottom: 12px; font-size: 14px; }
        @media print { .no-print { display: none; } body { margin: 0; } .receipt { border: 0; } }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="brand">{{ config('wibuy.name') }}</div>
        <h1>Receipt for order {{ $order->number }}</h1>

        <div class="grid">
            <div><strong>Paid on</strong><br>{{ $order->paid_at->format('j M Y, H:i') }}</div>
            <div><strong>Sold by</strong><br>{{ $order->seller->shop_name }}<br><span class="small">{{ $order->seller->district }}</span></div>
            <div><strong>For</strong><br>{{ $order->recipient_name }}<br><span class="small">{{ $order->district }}</span></div>
        </div>

        <table>
            <thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Total</th></tr></thead>
            <tbody>
                @foreach ($order->items as $item)
                    <tr><td>{{ $item->display_title }}</td><td class="r">{{ $item->quantity }} {{ $item->unit }}</td><td class="r">{{ money($item->unit_price) }}</td><td class="r">{{ money($item->line_total) }}</td></tr>
                @endforeach
                <tr><td colspan="3">Items</td><td class="r">{{ money($order->subtotal) }}</td></tr>
                @if ((float) $order->shop_discount > 0)
                    <tr><td colspan="3">Shop offer{{ $order->offer_labels ? ': '.$order->offer_labels : '' }}</td><td class="r">&minus; {{ money($order->shop_discount) }}</td></tr>
                @endif
                <tr><td colspan="3">Delivery</td><td class="r">{{ money($order->delivery_fee) }}</td></tr>
                @if ((float) $order->rider_tip > 0)
                    <tr><td colspan="3">Tip for the rider</td><td class="r">{{ money($order->rider_tip) }}</td></tr>
                @endif
                @if ((float) $order->discount > 0)
                    <tr><td colspan="3">Discount</td><td class="r">&minus; {{ money($order->discount) }}</td></tr>
                @endif
                <tr class="total"><td colspan="3">Paid</td><td class="r">{{ money($order->amount_paid) }}</td></tr>
            </tbody>
        </table>

        <p class="small">Status: {{ $order->statusLabel() }}. Paid through {{ config('wibuy.name') }}: your money is protected until you get your order.</p>
        <button class="no-print" onclick="window.print()">Print</button>
    </div>
</body>
</html>
