<?php

namespace App\Services;

use App\Enums\Operator;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Exceptions\MarketplaceException;
use App\Jobs\InitiatePaymentJob;
use App\Models\ImportOrder;
use App\Models\Inspection;
use App\Models\OrderMilestone;
use App\Models\Payment;
use App\Sms\SmsMessages;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Mobile money payments that are not a checkout: the balance of an order paid in parts, an inspection
 * fee, and import deposits and balances. Each is a "charge" payment pointing at what it pays for.
 */
class ChargeService
{
    public function __construct(private SmsService $sms) {}

    /** @throws MarketplaceException */
    public function create(Model $payable, string $amount, Operator $operator, string $phone, ?int $orderId = null): Payment
    {
        $pending = Payment::where('payable_type', $payable->getMorphClass())->where('payable_id', $payable->getKey())
            ->where('kind', PaymentKind::Charge)->where('status', PaymentStatus::Pending)->where('created_at', '>', now()->subMinutes(15))->exists();

        if ($pending) {
            throw new MarketplaceException('A payment request is already waiting. Approve it on your phone or try again in a few minutes.');
        }

        $payment = Payment::create([
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'order_id' => $orderId,
            'kind' => PaymentKind::Charge,
            'provider' => config('payments.gateway'),
            'provider_reference' => (string) Str::uuid(),
            'phone' => $phone,
            'operator' => $operator,
            'amount' => Money::from($amount),
            'status' => PaymentStatus::Pending,
        ]);

        InitiatePaymentJob::dispatch($payment->id)->afterCommit();

        return $payment;
    }

    /** The gateway finished a charge. Called inside the payment lock. Failed charges change nothing: pay again. */
    public function settle(Payment $payment, bool $paid): void
    {
        if (! $paid) {
            return;
        }

        $payable = $payment->payable()->lockForUpdate()->first();

        match (true) {
            $payable instanceof OrderMilestone => $this->milestonePaid($payable),
            $payable instanceof Inspection => $payable->status === 'pending_payment' && $payable->forceFill(['status' => 'waiting', 'paid_at' => now()])->save(),
            $payable instanceof ImportOrder => app(ImportService::class)->paid($payable, $payment),
            default => null,
        };
    }

    private function milestonePaid(OrderMilestone $milestone): void
    {
        if ($milestone->isPaid()) {
            return;
        }

        $milestone->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $order = $milestone->order()->with('seller.user')->first();

        if (! $order->hasUnpaidMilestones()) {
            $this->sms->send($order->seller->user->phone, SmsMessages::orderFullyPaid($order));
        }
    }
}
