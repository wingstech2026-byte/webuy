<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:cart-reminders', description: 'Remind people of items left in their cart for a day (once per change to the cart)')]
class RemindAbandonedCarts extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $sent = 0;

        // A cart whose last change is more than a day old and which was not reminded since that change.
        Cart::with('user')->withCount('items')->withMax('items', 'updated_at')->has('items')->get()
            ->filter(fn (Cart $cart) => $cart->items_max_updated_at !== null
                && now()->subDay()->gte($cart->items_max_updated_at)
                && ($cart->reminded_at === null || $cart->reminded_at->lt($cart->items_max_updated_at)))
            ->each(function (Cart $cart) use ($notifications, &$sent) {
                $notifications->notify($cart->user, '🛒 Still want these?', "You left {$cart->items_count} item(s) in your cart. They may sell out: finish your order when you are ready.", route('cart.index'));
                $cart->forceFill(['reminded_at' => now()])->save();
                $sent++;
            });

        $this->info("Reminded {$sent} cart(s).");

        return self::SUCCESS;
    }
}
