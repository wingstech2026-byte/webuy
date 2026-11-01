<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Requests\ReviewRequest;
use App\Models\Order;
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

        $order->load('items.review', 'items.product', 'seller.logo', 'sellerReview', 'openDispute', 'disputes', 'deliveryJob.rider', 'collectStation');

        return view('orders.show', [
            'order' => $order,
            'canReview' => $reviews->canReview($order, $request->user()),
            'isBuyer' => $order->buyer_id === $request->user()->id,
        ]);
    }

    /** "I got my order": completes it and pays the seller. */
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
