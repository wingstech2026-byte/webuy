<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Coupon;
use App\Models\GroupBuy;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Sms\SmsMessages;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The life of an order after it is paid:
 *   paid -> accepted -> shipped -> (delivered) -> completed          seller is credited on completed
 *   paid -> rejected | cancelled (seller too slow)                    buyer is refunded
 *   accepted/shipped/delivered -> disputed -> refunded | completed    decided by an admin
 * Every change locks the order row and checks its current status first.
 */
class OrderService
{
    public function __construct(
        private SmsService $sms,
        private LedgerService $ledger,
        private PaymentService $payments,
        private StockService $stock,
    ) {}

    /** Seller accepts a paid order (before the deadline). */
    public function accept(Order $order): Order
    {
        return $this->transition($order, [OrderStatus::Paid], function (Order $order) {
            $group = $order->groupParticipant?->group;

            if ($group && $group->status !== GroupBuy::SUCCEEDED) {
                throw new MarketplaceException('This is a group-buy order. Wait until the group is complete before accepting it.');
            }

            if ($order->accept_by && $order->accept_by->isPast()) {
                throw new MarketplaceException('The time to accept this order has passed. The buyer will be refunded.');
            }

            $order->forceFill(['status' => OrderStatus::Accepted, 'accepted_at' => now()])->save();
            $this->notifyBuyer($order, SmsMessages::orderAccepted(...));
        });
    }

    /** Seller rejects a paid order: the buyer is refunded in full. */
    public function reject(Order $order, string $reason): Order
    {
        return $this->transition($order, [OrderStatus::Paid], function (Order $order) use ($reason) {
            $order->forceFill([
                'status' => OrderStatus::Rejected,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ])->save();

            $this->releaseStock($order);
            $this->payments->refundOrder($order, "The seller could not fulfil your order: {$reason}");
        });
    }

    /** Seller sends the goods, or says they are ready for pickup. */
    public function ship(Order $order, ?string $note = null): Order
    {
        if ($order->hasUnpaidMilestones()) {
            throw new MarketplaceException('The buyer has not paid the balance yet. Ship once it is paid.');
        }

        return $this->transition($order, [OrderStatus::Accepted], function (Order $order) use ($note) {
            $order->forceFill(['status' => OrderStatus::Shipped, 'shipped_at' => now(), 'delivery_note' => $note])->save();
            $this->notifyBuyer($order, SmsMessages::orderShipped(...));
        });
    }

    /** Seller says the buyer has the goods. Texts the buyer to confirm; the auto-complete clock starts. */
    public function markDelivered(Order $order): Order
    {
        return $this->transition($order, [OrderStatus::Shipped], function (Order $order) {
            $order->forceFill(['status' => OrderStatus::Delivered, 'delivered_at' => now()])->save();
            $this->notifyBuyer($order, SmsMessages::deliveredReminder(...));
        });
    }

    /** The buyer confirms they received the order: it completes and the seller is credited. */
    public function confirmDelivery(Order $order, User $buyer): Order
    {
        if ($order->buyer_id !== $buyer->id) {
            throw new MarketplaceException('This is not your order.');
        }

        return $this->complete($order);
    }

    /**
     * Complete an order and credit the seller (total minus commission) and the WiBuy rider who delivered it. An order with an open
     * dispute cannot complete: the money stays on hold until an admin decides.
     */
    public function complete(Order $order, bool $afterDispute = false): Order
    {
        $allowed = [OrderStatus::Shipped, OrderStatus::Delivered];

        if ($afterDispute) {
            $allowed[] = OrderStatus::Disputed;
        }

        return $this->transition($order, $allowed, function (Order $order) {
            $order->forceFill(['status' => OrderStatus::Completed, 'completed_at' => now()])->save();

            $this->ledger->creditSale($order);
            $this->ledger->creditRider($order);
            app(StationService::class)->creditOnCompletion($order);

            // Sales count only once the order is done.
            $order->items()->get()->each(
                fn ($item) => Product::whereKey($item->product_id)->increment('sold_count', $item->quantity)
            );

            $this->creditSamples($order);
            app(ReferralService::class)->onOrderCompleted($order); // an invited friend's first order: both rewarded
        });
    }

    /** A paid order the seller never accepted: cancel it and refund the buyer. */
    public function cancelUnaccepted(Order $order): Order
    {
        return $this->transition($order, [OrderStatus::Paid], function (Order $order) {
            $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();

            $this->releaseStock($order);
            $this->payments->refundOrder($order, 'The seller did not accept your order in time.');
        });
    }

    /**
     * The buyer changed their mind before the seller accepted: cancelled, stock back, full refund.
     * Not for group-buy places (the group counts on them) or orders paid in parts.
     *
     * @throws MarketplaceException
     */
    public function cancelByBuyer(Order $order, User $buyer): Order
    {
        if ($order->buyer_id !== $buyer->id) {
            throw new MarketplaceException('This is not your order.');
        }

        if ($order->groupParticipant()->exists()) {
            throw new MarketplaceException('This is a group-buy order: it is refunded by itself if the group does not fill.');
        }

        if ($order->milestones()->exists()) {
            throw new MarketplaceException('Orders paid in parts cannot be cancelled here. Please contact WiBuy support.');
        }

        return $this->transition($order, [OrderStatus::Paid], function (Order $order) {
            $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now()])->save();

            $this->releaseStock($order);
            $this->payments->refundOrder($order, 'You cancelled the order before the shop accepted it.');
            $order->loadMissing('seller.user');
            $this->sms->send($order->seller->user->phone, "WiBuy: The buyer cancelled order {$order->number} before you accepted it. You do not need to do anything.");
        });
    }

    /** The refund went through. Called by PaymentService with the order locked. */
    public function markRefunded(Order $order): void
    {
        if ($order->status === OrderStatus::Refunded) {
            return;
        }

        $order->forceFill(['status' => OrderStatus::Refunded, 'refunded_at' => now()])->save();
        $order->loadMissing('buyer');

        $this->sms->send($order->buyer->phone, SmsMessages::refundIssued($order));
    }

    // ------------------------------------------------------------- scheduled jobs

    /** Paid orders the seller did not accept in 24 hours. @return int how many were refunded */
    public function refundUnaccepted(): int
    {
        $count = 0;

        Order::where('status', OrderStatus::Paid)->where('accept_by', '<=', now())->pluck('id')
            ->each(function (int $id) use (&$count) {
                try {
                    $this->cancelUnaccepted(Order::findOrFail($id));
                    $count++;
                } catch (MarketplaceException) {
                    // Changed state while we were looking: fine.
                }
            });

        return $count;
    }

    /** Shipped/delivered orders where the buyer did nothing for 3 days and nobody reported a problem. */
    public function autoComplete(): int
    {
        $cutoff = now()->subDays(config('wibuy.auto_complete_days'));
        $count = 0;

        Order::whereIn('status', [OrderStatus::Shipped, OrderStatus::Delivered])
            ->whereRaw('coalesce(delivered_at, shipped_at) <= ?', [$cutoff])
            ->pluck('id')
            ->each(function (int $id) use (&$count) {
                try {
                    $this->complete(Order::findOrFail($id));
                    $count++;
                } catch (MarketplaceException) {
                    // A dispute was opened a moment ago: leave it.
                }
            });

        return $count;
    }

    // ----------------------------------------------------------------- internals

    /**
     * Lock the order, check it is in one of the allowed statuses, run the change.
     *
     * @param  array<int, OrderStatus>  $from
     *
     * @throws MarketplaceException
     */
    private function transition(Order $order, array $from, callable $change): Order
    {
        return DB::transaction(function () use ($order, $from, $change) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if (! in_array($locked->status, $from, true)) {
                throw new MarketplaceException("This order is \"{$locked->status->label()}\", so that cannot be done now.");
            }

            $change($locked);

            return $locked->refresh();
        });
    }

    /** @param callable(Order): string $message */
    private function notifyBuyer(Order $order, callable $message): void
    {
        $order->load('buyer', 'seller', 'items');

        $this->sms->send($order->buyer->phone, $message($order));
    }

    /**
     * A sample arrived and was confirmed: the buyer gets its price back as a coupon on a later bulk order
     * of the same product (at least the minimum order quantity).
     */
    private function creditSamples(Order $order): void
    {
        foreach ($order->items()->where('is_sample', true)->with('product')->get() as $item) {
            $product = $item->product;

            if (! $product || Coupon::where('user_id', $order->buyer_id)->where('source', 'sample')->where('product_id', $product->id)->exists()) {
                continue;
            }

            app(CouponService::class)->issue($order->buyer, [
                'title' => 'Sample credit: '.$product->title,
                'source' => 'sample',
                'amount' => $item->unit_price,
                'product_id' => $product->id,
                'min_spend' => Money::mul($product->price, max(2, $product->min_order_quantity)),
                'expires_at' => now()->addDays(60),
            ]);
        }
    }

    /** A group buy failed: cancel the paid order, put the stock back and refund (all safe to repeat). */
    public function cancelFailedGroup(Order $order): Order
    {
        return $this->transition($order, [OrderStatus::Paid], function (Order $order) {
            $order->forceFill(['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'accept_by' => null])->save();

            $this->releaseStock($order);
            $this->payments->refundOrder($order, 'The group did not fill up in time. Your money is refunded.');
        });
    }

    /**
     * Give the reserved units back to the products, once: the stock_reserved flag makes it safe to repeat.
     * Called with the order row locked.
     */
    private function releaseStock(Order $order): void
    {
        if (! $order->stock_reserved) {
            return;
        }

        $this->stock->release($this->stock->linesOf($order->items()->get()));

        $order->forceFill(['stock_reserved' => false])->save();
    }
}
