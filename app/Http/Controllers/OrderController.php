<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Requests\ReviewRequest;
use App\Models\Order;
use App\Services\BuyAgainService;
use App\Services\DeliveryService;
use App\Services\OrderService;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The buyer's orders. */
class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = $request->user()->orders()
            ->where('status', '!=', OrderStatus::PendingPayment)
            ->with('items', 'seller')
            ->latest('id')
            ->paginate(15);

        return view('orders.index', ['orders' => $orders]);
    }

    public function show(Order $order, ReviewService $reviews, Request $request)
    {
        $this->authorize('view', $order);

        $order->load('items.review', 'items.product', 'seller.logo', 'sellerReview', 'openDispute', 'disputes', 'deliveryJob.rider', 'collectStation', 'repeat');

        return view('orders.show', [
            'order' => $order,
            'canReview' => $reviews->canReview($order, $request->user()),
            'isBuyer' => $order->buyer_id === $request->user()->id,
        ]);
    }

    /** "I got my order": completes it and pays the seller. */
    /** Stars for the rider who delivered it. */
    public function rateRider(Request $request, Order $order, DeliveryService $deliveries): RedirectResponse
    {
        $this->authorize('buy', $order);
        $data = $request->validate(['stars' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:300']], ['stars.*' => 'Choose 1 to 5 stars.']);

        try {
            $deliveries->rate($order, $request->user(), (int) $data['stars'], $data['comment'] ?? null);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Thank you for rating the rider!');
    }

    /** "Buy again": the same things back in the cart, at today's price. */
    public function buyAgain(Order $order, BuyAgainService $buyAgain, Request $request): RedirectResponse
    {
        $this->authorize('buy', $order);
        $result = $buyAgain->addToCart($order, $request->user());

        if ($result['added'] === 0) {
            return back()->withErrors(['general' => 'None of these products can be bought now.']);
        }

        return redirect()->route('cart.index')->with('status', "Added {$result['added']} item(s) to your cart."
            .($result['skipped'] ? ' Not available now: '.implode(', ', $result['skipped']).'.' : ''));
    }

    /** "Remind me to order this again every N days" (0: stop). */
    public function repeat(Order $order, BuyAgainService $buyAgain, Request $request): RedirectResponse
    {
        $this->authorize('buy', $order);
        $days = (int) $request->validate(['every_days' => ['required', 'integer', 'in:0,'.implode(',', BuyAgainService::REPEAT_DAYS)]])['every_days'];
        $buyAgain->remindEvery($order, $request->user(), $days ?: null);

        return back()->with('status', $days ? "We will remind you to order again every {$days} days." : 'Reminder stopped.');
    }

    /** A printable receipt (buyer, the shop and admins). */
    public function receipt(Order $order)
    {
        $this->authorize('view', $order);
        abort_if($order->paid_at === null, 404);

        return view('orders.receipt', ['order' => $order->load('items', 'seller', 'buyer')]);
    }

    /** "Cancel my order": only before the shop accepts it; the money comes back in full. */
    public function cancel(Order $order, OrderService $orders, Request $request): RedirectResponse
    {
        $this->authorize('buy', $order);

        try {
            $orders->cancelByBuyer($order, $request->user());
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Order cancelled. Your money is on its way back to your mobile money.');
    }

    public function confirm(Order $order, OrderService $orders, Request $request): RedirectResponse
    {
        $this->authorize('buy', $order);

        try {
            $orders->confirmDelivery($order, $request->user());
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order)->with('status', 'Thank you! Your order is complete. Please rate the seller below.');
    }

    /** Live delivery tracking for the order page (polled every few seconds while a rider carries it). */
    public function tracking(Order $order): JsonResponse
    {
        $this->authorize('buy', $order);

        return response()->json(['rider_delivery' => $order->load('deliveryJob.rider', 'deliveryJob.dropoffStation')->riderDelivery()]);
    }

    public function review(ReviewRequest $request, Order $order, ReviewService $reviews): RedirectResponse
    {
        $this->authorize('buy', $order);

        $data = $request->validated();

        try {
            $reviews->submit(
                $order,
                $request->user(),
                isset($data['seller_rating']) ? (int) $data['seller_rating'] : null,
                $data['seller_comment'] ?? null,
                $data['items'] ?? [],
            );
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('orders.show', $order)->with('status', 'Thank you for your review!');
    }
}
