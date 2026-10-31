<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\GroupBuy;
use App\Models\Payment;
use App\Services\GroupBuyService;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:expire-groups', description: 'Close group buys whose time ran out: refund paid members and give the stock back')]
class ExpireGroupBuys extends Command
{
    public function handle(GroupBuyService $groups, PaymentService $payments): int
    {
        // A member may have approved at the last second: ask the gateway before closing the group.
        $checkoutIds = GroupBuy::where('status', GroupBuy::OPEN)->where('expires_at', '<=', now())
            ->with('participants')->get()
            ->flatMap(fn (GroupBuy $group) => $group->participants->where('status', 'pending')->pluck('checkout_id'))
            ->filter();

        Payment::whereIn('checkout_id', $checkoutIds)->where('status', PaymentStatus::Pending)->get()
            ->each(fn (Payment $payment) => $payments->syncQuietly($payment));

        $this->info('Closed '.$groups->expireDue().' group(s).');

        return self::SUCCESS;
    }
}
