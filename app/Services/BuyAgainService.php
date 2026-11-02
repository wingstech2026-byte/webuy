<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Order;
use App\Models\OrderRepeat;
use App\Models\User;

/** "Buy again": an old order back in the cart in one tap, and reminders to reorder things bought often (rice, soap). */
class BuyAgainService
{
    public const REPEAT_DAYS = [7, 14, 30, 60];

    public function __construct(private CartService $cart) {}

    /**
     * Puts what is still on sale back in the cart, at today's price; the rest is listed as skipped.
     *
     * @return array{added: int, skipped: list<string>}
     */
    public function addToCart(Order $order, User $buyer): array
    {
        $added = 0;
        $skipped = [];

        foreach ($order->items()->with('product')->get() as $item) {
            try {
                $this->cart->add($buyer, $item->product, $item->quantity, $item->variant_id);
                $added++;
            } catch (MarketplaceException) {
                $skipped[] = $item->title;
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** Every N days (or null: stop). */
    public function remindEvery(Order $order, User $buyer, ?int $days): ?OrderRepeat
    {
        if ($days === null) {
            OrderRepeat::where('order_id', $order->id)->where('user_id', $buyer->id)->update(['is_active' => false]);

            return null;
        }

        return OrderRepeat::updateOrCreate(
            ['order_id' => $order->id, 'user_id' => $buyer->id],
            ['every_days' => $days, 'next_at' => now()->addDays($days), 'is_active' => true],
        );
    }
}
