<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/** Buyers see their own orders, sellers see orders for their shop, admins see all. */
class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->is_admin || $order->buyer_id === $user->id || $this->isOrderSeller($user, $order);
    }

    /** Accept, reject, ship, mark delivered: the shop that received the order. */
    public function manage(User $user, Order $order): bool
    {
        return $this->isOrderSeller($user, $order);
    }

    /** Confirm delivery, report a problem, review: the buyer. */
    public function buy(User $user, Order $order): bool
    {
        return $order->buyer_id === $user->id;
    }

    private function isOrderSeller(User $user, Order $order): bool
    {
        return $user->isSeller() && $user->seller->id === $order->seller_id;
    }
}
