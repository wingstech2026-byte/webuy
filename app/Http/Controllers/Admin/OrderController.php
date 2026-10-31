<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $status = OrderStatus::tryFrom((string) $request->query('status'));
        $term = trim((string) $request->query('q'));

        $items = Order::with('buyer', 'seller', 'items')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($term !== '', fn ($q) => $q->where('number', 'like', '%'.strtoupper($term).'%'))
            ->latest('id')->paginate(25)->withQueryString();

        return view('admin.orders.index', ['items' => $items, 'status' => $status, 'term' => $term]);
    }

    public function show(Order $order)
    {
        return view('admin.orders.show', [
            'order' => $order->load('items', 'buyer', 'seller', 'payments', 'disputes', 'checkout'),
        ]);
    }

    /** A refund the gateway rejected can be sent again with a fresh reference. */
    public function retryRefund(Order $order, PaymentService $payments): RedirectResponse
    {
        $last = $order->payments()->where('kind', PaymentKind::Refund)->latest('id')->first();

        if (! $last || $last->status !== PaymentStatus::Failed) {
            return back()->withErrors(['general' => 'There is no failed refund to retry for this order.']);
        }

        // A refund for returned items is sent again for the same part, never as a full refund.
        if ($last->payable instanceof ReturnRequest) {
            $payments->refundPart($order, $last->payable, $order->refund_reason ?? 'Refund retried by an admin.');
        } else {
            $payments->refundOrder($order, $order->refund_reason ?? 'Refund retried by an admin.');
        }

        return back()->with('status', 'Refund sent again.');
    }
}
