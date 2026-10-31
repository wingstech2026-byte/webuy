<?php

namespace App\Sms;

use App\Models\Checkout;
use App\Models\Dispute;
use App\Models\FlashDeal;
use App\Models\ImportOrder;
use App\Models\Inspection;
use App\Models\Membership;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Seller;
use App\Support\Money;

/**
 * Every SMS carries the WiBuy name and tagline. Callers load the relations noted on each message.
 */
final class SmsMessages
{
    private static function wrap(string $body): string
    {
        return config('wibuy.name').': '.$body.' '.config('wibuy.tagline');
    }

    public static function otp(string $code, int $minutes): string
    {
        return self::wrap("{$code} is your login code. It expires in {$minutes} minutes.");
    }

    /** To the seller. Needs order.items. */
    public static function orderPlaced(Order $order): string
    {
        return self::wrap(
            "New order {$order->number}: {$order->items->sum('quantity')} item(s), ".Money::format($order->total)
            .' paid. Please accept it within '.config('wibuy.accept_within_hours').' hours in your seller dashboard.'
        );
    }

    /** To the buyer, once per checkout. */
    public static function paymentReceived(Checkout $checkout): string
    {
        return self::wrap('We got your payment of '.Money::format($checkout->total).'. The sellers will confirm your order soon.');
    }

    /** To the buyer. */
    public static function orderAccepted(Order $order): string
    {
        return self::wrap("Good news: {$order->seller->shop_name} accepted your order {$order->number}.");
    }

    /** To the buyer. */
    public static function orderShipped(Order $order): string
    {
        $what = $order->isPickup()
            ? "is ready for pickup at {$order->seller->pickup_address}"
            : 'is on its way';

        return self::wrap("Your order {$order->number} {$what}.".($order->delivery_note ? " Note: {$order->delivery_note}." : ''));
    }

    /** To the buyer when the seller marks it delivered. */
    public static function deliveredReminder(Order $order): string
    {
        $days = config('wibuy.auto_complete_days');

        return self::wrap(
            "Order {$order->number} was marked delivered. Please confirm you got it. "
            ."If we hear nothing it completes by itself in {$days} days."
        );
    }

    /** To the buyer. */
    public static function refundIssued(Order $order): string
    {
        return self::wrap('We refunded '.Money::format($order->amount_paid)." to your mobile money for order {$order->number}.");
    }

    /** To the seller. */
    public static function payoutSent(Payout $payout): string
    {
        return self::wrap('We sent your payout of '.Money::format($payout->amount).' to your mobile money.');
    }

    public static function payoutRejected(Payout $payout): string
    {
        return self::wrap('Your payout request of '.Money::format($payout->amount).' was not approved'
            .($payout->rejection_reason ? ": {$payout->rejection_reason}." : '.').' The money is back in your balance.');
    }

    public static function sellerApproved(Seller $seller): string
    {
        return self::wrap("Your shop {$seller->shop_name} is approved. You can now add products and start selling.");
    }

    public static function sellerRejected(Seller $seller): string
    {
        return self::wrap("Your seller application for {$seller->shop_name} was not approved".($seller->rejection_reason ? ": {$seller->rejection_reason}." : '.'));
    }

    /** To the seller. Needs dispute.order. */
    public static function disputeOpened(Dispute $dispute): string
    {
        return self::wrap("A buyer reported a problem with order {$dispute->order->number}. Please reply in your seller dashboard.");
    }

    /** To the buyer or seller. Needs dispute.order. */
    public static function disputeResolved(Dispute $dispute, bool $forBuyer): string
    {
        return self::wrap("The problem with order {$dispute->order->number} was decided: ".($forBuyer ? 'the buyer is refunded' : 'the seller is paid').'.');
    }

    /** Sent at most once an hour per conversation, and never for system messages. */
    public static function newChatMessages(): string
    {
        return self::wrap('You have new messages on WiBuy. Open '.route('chat.index').' to read them.');
    }

    /** To the seller: an order paid in parts is now fully paid. */
    public static function orderFullyPaid(Order $order): string
    {
        return self::wrap("Order {$order->number} is fully paid. You can ship it now.");
    }

    public static function inspectionResult(Inspection $inspection): string
    {
        return self::wrap("Inspection of order {$inspection->order->number}: ".($inspection->status === 'passed'
            ? 'PASSED. You can pay the balance in the app.'
            : 'FAILED. Do not pay the balance; report a problem in the app and your deposit is refunded through the dispute process.'));
    }

    public static function importUpdate(ImportOrder $order): string
    {
        return self::wrap("WiBuy Global {$order->number}: {$order->status_label}.".($order->status === 'ready_for_pickup' ? ' Pay the balance of '.Money::format($order->balance_amount).' in the app to collect it.' : ''));
    }

    /** To someone who tapped "Remind me". Needs deal.product. */
    public static function flashDealStarting(FlashDeal $deal): string
    {
        return self::wrap("Lightning deal now: {$deal->product->title} for ".Money::format($deal->deal_price).' until '.$deal->ends_at->format('g:ia').'. '.$deal->product->url);
    }

    public static function passStarted(Membership $membership): string
    {
        return self::wrap('Your WiBuy Pass is active until '.$membership->ends_at->format('j M').'. Enjoy free delivery, member prices and extra spins.');
    }

    /** Before the pass ends. Nothing is charged automatically. */
    public static function passEnding(Membership $membership): string
    {
        return self::wrap('Your WiBuy Pass ends on '.$membership->ends_at->format('j M').'. Renew it in the app if you want to keep your benefits. We never charge you automatically.');
    }
}
