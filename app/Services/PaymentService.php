<?php

namespace App\Services;

use App\Enums\Operator;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Jobs\InitiatePaymentJob;
use App\Models\Checkout;
use App\Models\Membership;
use App\Models\Order;
use App\Models\OrderMilestone;
use App\Models\Payment;
use App\Models\Payout;
use App\Payments\GatewayResult;
use App\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Talks to the payment gateway and applies the answer to our records.
 * Every status change goes through apply(), which is safe to call twice.
 *
 * Lock order: the parent record first (checkout, order or payout), then the payment.
 * CheckoutService, OrderService and PayoutService are resolved lazily because they also use this class.
 */
class PaymentService
{
    /** A payment the gateway has never heard of is given up on after this many minutes. */
    private const GIVE_UP_UNKNOWN_AFTER_MINUTES = 30;

    public function __construct(private PaymentGateway $gateway) {}

    // ------------------------------------------------------------ creating

    public function createDeposit(Checkout $checkout, Operator $operator, string $phone): Payment
    {
        $payment = Payment::create([
            'checkout_id' => $checkout->id,
            'kind' => PaymentKind::Deposit,
            'provider' => config('payments.gateway'),
            'provider_reference' => (string) Str::uuid(),
            'phone' => $phone,
            'operator' => $operator,
            'amount' => $checkout->total,
            'status' => PaymentStatus::Pending,
        ]);

        InitiatePaymentJob::dispatch($payment->id)->afterCommit();

        return $payment;
    }

    /** Ask the buyer's phone to pay for one month of WiBuy Pass (a deposit with no order). */
    public function createMembershipPayment(Membership $membership): Payment
    {
        $payment = Payment::create([
            'membership_id' => $membership->id,
            'kind' => PaymentKind::Membership,
            'provider' => config('payments.gateway'),
            'provider_reference' => (string) Str::uuid(),
            'phone' => $membership->payer_phone,
            'operator' => $membership->operator,
            'amount' => $membership->price,
            'status' => PaymentStatus::Pending,
        ]);

        InitiatePaymentJob::dispatch($payment->id)->afterCommit();

        return $payment;
    }

    /** Pay a seller their approved payout. */
    public function createPayout(Payout $payout): Payment
    {
        $payment = Payment::create([
            'payout_id' => $payout->id,
            'kind' => PaymentKind::Payout,
            'provider' => config('payments.gateway'),
            'provider_reference' => (string) Str::uuid(),
            'phone' => $payout->phone,
            'operator' => $payout->operator,
            'amount' => $payout->amount,
            'status' => PaymentStatus::Pending,
        ]);

        InitiatePaymentJob::dispatch($payment->id)->afterCommit();

        return $payment;
    }

    /**
     * Start refunding one order (its full total, delivery fee included) from the checkout's deposit.
     * Safe to call twice. Returns null when there is nothing to refund or a refund already exists.
     */
    public function refundOrder(Order $order, string $reason): ?Payment
    {
        $deposit = Payment::where('checkout_id', $order->checkout_id)
            ->where('kind', PaymentKind::Deposit)->where('status', PaymentStatus::Completed)
            ->latest('id')->first();

        if (! $deposit) {
            return null;
        }

        if ($order->milestones()->exists()) {
            return $this->refundInParts($order, $deposit, $reason);
        }

        $alreadyRefunding = Payment::where('order_id', $order->id)->where('kind', PaymentKind::Refund)
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Completed])->exists();

        if ($alreadyRefunding) {
            return null;
        }

        $order->forceFill(['refund_reason' => $reason])->save();

        $refund = Payment::create([
            'checkout_id' => $order->checkout_id,
            'order_id' => $order->id,
            'kind' => PaymentKind::Refund,
            'provider' => $deposit->provider,
            'provider_reference' => (string) Str::uuid(),
            'phone' => $deposit->phone,
            'operator' => $deposit->operator,
            'amount' => $order->amount_paid, // coupons and free delivery were paid by WiBuy, not the buyer
            'status' => PaymentStatus::Pending,
        ]);

        InitiatePaymentJob::dispatch($refund->id)->afterCommit();

        return $refund;
    }

    /**
     * An order paid in parts: give back each part from the payment it came from (the deposit, then any
     * balance paid). Each refund points at its source payment.
     */
    private function refundInParts(Order $order, Payment $deposit, string $reason): ?Payment
    {
        if (Payment::where('order_id', $order->id)->where('kind', PaymentKind::Refund)
            ->whereIn('status', [PaymentStatus::Pending, PaymentStatus::Completed])->exists()) {
            return null;
        }

        $order->forceFill(['refund_reason' => $reason])->save();

        $sources = collect([$deposit])->merge(
            Payment::where('kind', PaymentKind::Charge)->where('payable_type', (new OrderMilestone)->getMorphClass())
                ->whereIn('payable_id', $order->milestones()->select('id'))
                ->where('status', PaymentStatus::Completed)->get()
        );

        $first = null;

        foreach ($sources as $source) {
            $refund = Payment::create([
                'checkout_id' => $order->checkout_id,
                'order_id' => $order->id,
                'refund_of_id' => $source->id,
                'kind' => PaymentKind::Refund,
                'provider' => $source->provider,
                'provider_reference' => (string) Str::uuid(),
                'phone' => $source->phone,
                'operator' => $source->operator,
                'amount' => $source->amount,
                'status' => PaymentStatus::Pending,
            ]);

            InitiatePaymentJob::dispatch($refund->id)->afterCommit();
            $first ??= $refund;
        }

        return $first;
    }

    public function refundCheckout(Checkout $checkout, string $reason): void
    {
        Order::where('checkout_id', $checkout->id)->get()
            ->each(fn (Order $order) => $this->refundOrder($order, $reason));
    }

    // ----------------------------------------------------------- gateway calls

    /** Send a pending deposit, refund or payout to the gateway (runs on the queue). */
    public function initiate(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Pending || isset($payment->raw_response['initiation'])) {
            return;
        }

        $result = match ($payment->kind) {
            PaymentKind::Deposit, PaymentKind::Membership, PaymentKind::Charge => $this->gateway->initiateDeposit($payment),
            PaymentKind::Payout => $this->gateway->initiatePayout($payment),
            PaymentKind::Refund => $this->gateway->initiateRefund($payment, $this->depositFor($payment)),
        };

        $payment->forceFill(['raw_response' => array_merge($payment->raw_response ?? [], ['initiation' => $result->raw ?: ['sent' => true]])])->save();

        $this->apply($payment, $result);
    }

    private function depositFor(Payment $refund): Payment
    {
        if ($refund->refund_of_id) {
            return Payment::findOrFail($refund->refund_of_id);
        }

        return Payment::where('checkout_id', $refund->checkout_id)
            ->where('kind', PaymentKind::Deposit)->where('status', PaymentStatus::Completed)
            ->latest('id')->firstOrFail();
    }

    /** Re-fetch the real status from the gateway and apply it. Used by the webhook, the poller and the scheduler. */
    public function sync(Payment $payment): void
    {
        $payment->refresh();

        if ($payment->status === PaymentStatus::Completed) {
            return;
        }

        $result = $this->gateway->fetchStatus($payment);

        if ($result->notFound) {
            $tooOld = $payment->created_at->lt(now()->subMinutes(self::GIVE_UP_UNKNOWN_AFTER_MINUTES));

            if ($payment->status === PaymentStatus::Pending && $tooOld) {
                $this->apply($payment, GatewayResult::failed('Not found at the payment provider', $result->raw));
            }

            return;
        }

        $this->apply($payment, $result);
    }

    /** Like sync() but never throws. For page loads and batch jobs. */
    public function syncQuietly(Payment $payment): void
    {
        try {
            $this->sync($payment);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function markFailed(Payment $payment, string $reason): void
    {
        $this->apply($payment, GatewayResult::failed($reason));
    }

    // ------------------------------------------------------------- applying

    /** Apply a gateway answer. Idempotent: replaying the same answer changes nothing. */
    public function apply(Payment $payment, GatewayResult $result): void
    {
        if ($result->status === PaymentStatus::Pending) {
            $payment->forceFill(['raw_response' => $this->mergeRaw($payment, $result)])->save();

            return;
        }

        DB::transaction(function () use ($payment, $result) {
            $checkout = $order = $payout = $membership = null;

            match ($payment->kind) {
                PaymentKind::Deposit => $checkout = Checkout::lockForUpdate()->find($payment->checkout_id),
                PaymentKind::Refund => $order = Order::lockForUpdate()->find($payment->order_id),
                PaymentKind::Payout => $payout = Payout::lockForUpdate()->find($payment->payout_id),
                PaymentKind::Membership => $membership = Membership::lockForUpdate()->find($payment->membership_id),
                PaymentKind::Charge => null,
            };

            $locked = Payment::lockForUpdate()->find($payment->id);

            // Already done. A deposit we wrongly gave up on may still turn out completed.
            if ($locked->status === PaymentStatus::Completed || $locked->status === $result->status) {
                return;
            }

            $locked->forceFill([
                'status' => $result->status,
                'raw_response' => $this->mergeRaw($locked, $result),
            ])->save();

            match ($locked->kind) {
                PaymentKind::Deposit => $this->applyDeposit($checkout, $result),
                PaymentKind::Refund => $this->applyRefund($order, $result),
                PaymentKind::Payout => $this->applyPayout($payout, $result),
                PaymentKind::Membership => app(MembershipService::class)->settle($membership, $result->status === PaymentStatus::Completed),
                PaymentKind::Charge => app(ChargeService::class)->settle($locked, $result->status === PaymentStatus::Completed),
            };
        });

        $payment->refresh();
    }

    private function applyDeposit(Checkout $checkout, GatewayResult $result): void
    {
        $checkouts = app(CheckoutService::class);

        if ($result->status === PaymentStatus::Completed) {
            if (! $checkouts->settlePaid($checkout)) {
                $this->refundCheckout($checkout, 'Some items sold out before your payment arrived.');
            }

            return;
        }

        $checkouts->cancelUnpaid($checkout);
    }

    private function applyRefund(Order $order, GatewayResult $result): void
    {
        // A failed refund leaves the order as it is so an admin can retry it. Paid in parts: wait for every part.
        $partsLeft = Payment::where('order_id', $order->id)->where('kind', PaymentKind::Refund)
            ->where('status', PaymentStatus::Pending)->exists();

        if ($result->status === PaymentStatus::Completed && ! $partsLeft) {
            app(OrderService::class)->markRefunded($order);
        }
    }

    private function applyPayout(Payout $payout, GatewayResult $result): void
    {
        $payouts = app(PayoutService::class);

        $result->status === PaymentStatus::Completed ? $payouts->markPaid($payout) : $payouts->markFailed($payout);
    }

    private function mergeRaw(Payment $payment, GatewayResult $result): array
    {
        $merged = array_merge($payment->raw_response ?? [], ['last_status' => $result->raw]);

        if ($result->reason) {
            $merged['reason'] = $result->reason;
        }

        return $merged;
    }
}
