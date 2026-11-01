<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReasonRequest;
use App\Http\Requests\Seller\RejectOrderRequest;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /** Tabs on the orders page. */
    public const TABS = [
        'new' => [OrderStatus::Paid],
        'accepted' => [OrderStatus::Accepted],
        'shipped' => [OrderStatus::Shipped],
        'delivered' => [OrderStatus::Delivered],
        'problems' => [OrderStatus::Disputed],
        'finished' => [OrderStatus::Completed, OrderStatus::Rejected, OrderStatus::Cancelled, OrderStatus::Refunded],
    ];

    public function __construct(private OrderService $orders) {}

    public function index(Request $request)
    {
        $seller = $request->user()->seller;
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'new';

        $counts = Order::where('seller_id', $seller->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $tabCounts = collect(self::TABS)->map(fn ($statuses) => collect($statuses)->sum(fn ($s) => (int) ($counts[$s->value] ?? 0)));

        return view('seller.orders.index', [
            'orders' => Order::where('seller_id', $seller->id)->whereIn('status', self::TABS[$tab])
                ->with('items', 'buyer')->orderByDesc('id')->paginate(20)->withQueryString(),
            'tab' => $tab,
            'tabCounts' => $tabCounts,
        ]);
    }

    public function show(Order $order)
    {
        $this->authorize('manage', $order);

        return view('seller.orders.show', ['order' => $order->load('items', 'buyer', 'openDispute', 'deliveryJob.rider')]);
    }

    public function accept(Order $order): RedirectResponse
    {
        return $this->run($order, fn () => $this->orders->accept($order), 'Order accepted. The buyer was told.');
    }

    public function reject(RejectOrderRequest $request, Order $order): RedirectResponse
    {
        return $this->run($order, fn () => $this->orders->reject($order, $request->validated('reason')), 'Order rejected. The buyer is being refunded.');
    }

    public function ship(ReasonRequest $request, Order $order): RedirectResponse
    {
        $message = $order->isPickup() ? 'Marked as ready for pickup. The buyer was told.' : 'Marked as shipped. The buyer was told.';

        return $this->run($order, fn () => $this->orders->ship($order, $request->validated('reason')), $message);
    }

    public function deliver(Order $order): RedirectResponse
    {
        return $this->run($order, fn () => $this->orders->markDelivered($order), 'Marked as delivered. The buyer was asked to confirm.');
    }

    private function run(Order $order, callable $action, string $success): RedirectResponse
    {
        $this->authorize('manage', $order);

        try {
            $action();
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('seller.orders.show', $order)->with('status', $success);
    }
}
