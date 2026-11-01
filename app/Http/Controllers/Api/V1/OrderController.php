<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/** The buyer's orders. Selling and admin work stay on the website. */
class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $request->user()->orders()
            ->where('status', '!=', OrderStatus::PendingPayment)
            ->with('items', 'seller')
            ->latest('id')
            ->paginate(20);

        return OrderResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        abort_if(Gate::denies('buy', $order), 404); // 404, not 403: never confirm someone else's order exists

        return new OrderResource($order->load('items.review', 'items.product', 'seller.logo', 'sellerReview', 'deliveryJob.rider', 'collectStation'));
    }

    /** "I got my order": it completes and the seller is paid. */
    public function confirm(Order $order, OrderService $orders, Request $request): OrderResource
    {
        abort_if(Gate::denies('buy', $order), 404); // 404, not 403: never confirm someone else's order exists

        $orders->confirmDelivery($order, $request->user());

        return $this->show($order->refresh());
    }

    public function review(ReviewRequest $request, Order $order, ReviewService $reviews): OrderResource
    {
        abort_if(Gate::denies('buy', $order), 404); // 404, not 403: never confirm someone else's order exists

        $data = $request->validated();

        $reviews->submit(
            $order,
            $request->user(),
            isset($data['seller_rating']) ? (int) $data['seller_rating'] : null,
            $data['seller_comment'] ?? null,
            $data['items'] ?? [],
        );

        return $this->show($order->refresh());
    }
}
