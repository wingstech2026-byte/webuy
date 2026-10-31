<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Re-fetches a payment's real status from the gateway (webhook, poller and scheduler all use this). */
class SyncPaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $paymentId)
    {
        $this->afterCommit();
    }

    /** @return int[] */
    public function backoff(): array
    {
        return [15, 90];
    }

    public function handle(PaymentService $payments): void
    {
        if ($payment = Payment::find($this->paymentId)) {
            $payments->sync($payment);
        }
    }
}
