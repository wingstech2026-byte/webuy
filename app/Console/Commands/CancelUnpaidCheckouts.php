<?php

namespace App\Console\Commands;

use App\Enums\CheckoutStatus;
use App\Enums\PaymentStatus;
use App\Models\Checkout;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'wibuy:cancel-unpaid', description: 'Cancel checkouts not paid within the hold time and give their stock back')]
class CancelUnpaidCheckouts extends Command
{
    public function handle(CheckoutService $checkouts, PaymentService $payments): int
    {
        $cancelled = 0;

        Checkout::where('status', CheckoutStatus::PendingPayment)->where('expires_at', '<=', now())
            ->with('payments')->get()
            ->each(function (Checkout $checkout) use ($checkouts, $payments, &$cancelled) {
                // The buyer may have approved at the last second: ask the gateway first.
                $checkout->payments->where('status', PaymentStatus::Pending)->each(fn ($p) => $payments->syncQuietly($p));

                $cancelled += $checkouts->cancelUnpaid($checkout) ? 1 : 0;
            });

        $this->info("Cancelled {$cancelled} unpaid checkout(s).");

        return self::SUCCESS;
    }
}
