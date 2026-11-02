<?php

namespace App\Console\Commands;

use App\Models\OrderRepeat;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:repeat-reminders', description: 'Remind buyers to order again things they asked to be reminded about')]
class RemindRepeatOrders extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $due = OrderRepeat::with('user', 'order.seller')->where('is_active', true)->where('next_at', '<=', now())->get();

        foreach ($due as $repeat) {
            $notifications->notify($repeat->user, '🔁 Time to order again?', "It is {$repeat->every_days} days since your order from {$repeat->order->seller->shop_name}. Tap \"Buy again\" to put the same things in your cart.", route('orders.show', $repeat->order));
            $repeat->forceFill(['next_at' => now()->addDays($repeat->every_days)])->save();
        }

        $this->info("Sent {$due->count()} reminder(s).");

        return self::SUCCESS;
    }
}
