<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Order;
use App\Services\BuyAgainService;
use App\Services\DeliveryService;
use App\Services\OrderService;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
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

        return new OrderResource($order->load('items.review', 'items.product', 'seller.logo', 'sellerReview', 'deliveryJob.rider', 'collectStation', 'repeat'));
    }

    /** Stars for the rider who delivered it. */
    public function rateRider(Request $request, Order $order, DeliveryService $deliveries): OrderResource
    {
        abort_if(Gate::denies('buy', $order), 404);
        $data = $request->validate(['stars' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:300']], ['stars.*' => 'Choose 1 to 5 stars.']);
        $deliveries->rate($order, $request->user(), (int) $data['stars'], $data['comment'] ?? null);

        return $this->show($order->refresh());
    }

    /** "Buy again": the same things back in the cart, at today's price. */
    public function buyAgain(Request $request, Order $order, BuyAgainService $buyAgain): JsonResponse
    {
        abort_if(Gate::denies('buy', $order), 404);
        $result = $buyAgain->addToCart($order, $request->user());

        return response()->json($result + [
            'message' => $result['added'] ? "Added {$result['added']} item(s) to your cart." : 'None of these products can be bought now.',
        ], $result['added'] ? 200 : 422);
    }

    /** "Remind me to order again every N days" (0: stop). */
    public function repeat(Request $request, Order $order, BuyAgainService $buyAgain): OrderResource
    {
        abort_if(Gate::denies('buy', $order), 404);
        $days = (int) $request->validate(['every_days' => ['required', 'integer', 'in:0,'.implode(',', BuyAgainService::REPEAT_DAYS)]])['every_days'];
        $buyAgain->remindEvery($order, $request->user(), $days ?: null);

        return $this->show($order->refresh());
    }

    /** "Cancel my order": only before the shop accepts it; full refund. */
    public function cancel(Request $request, Order $order, OrderService $orders): OrderResource
    {
        abort_if(Gate::denies('buy', $order), 404);
        $orders->cancelByBuyer($order, $request->user());

        return $this->show($order->refresh());
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
