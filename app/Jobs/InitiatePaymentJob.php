<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a deposit, refund or payout request to the payment gateway. Retried, and safe to repeat
 * because the payment's UUID is the idempotency key at the gateway.
 */
class InitiatePaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $paymentId) {}

    /** @return int[] */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(PaymentService $payments): void
    {
        if (! $payment = Payment::find($this->paymentId)) {
            return;
        }

        try {
            $payments->initiate($payment);
        } catch (Throwable $e) {
            // A real queue retries. A sync "queue" runs inside the web request and cannot retry,
            // so give up cleanly instead of showing the customer an error page.
            if ($this->job?->getConnectionName() === 'sync') {
                report($e);
                $this->failed($e);

                return;
            }

            throw $e;
        }
    }

    /** After the last attempt: fail the payment (deposits cancel the checkout, payouts go back to the balance). */
    public function failed(Throwable $e): void
    {
        if ($payment = Payment::find($this->paymentId)) {
            app(PaymentService::class)->markFailed($payment, 'Could not reach the payment provider.');
        }
    }
}
