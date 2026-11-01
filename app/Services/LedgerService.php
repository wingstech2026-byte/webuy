<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Models\DeliveryJob;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Seller;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A seller's (or a WiBuy rider's) money. Every credit and debit is a ledger row and rows are never changed.
 *   available = sum of the ledger (completed sales minus payouts)
 *   pending   = what the seller will earn from orders still in progress
 *   paid out  = payouts that were sent
 * When a WiBuy rider delivered the order, the delivery fee goes to the rider instead of the seller.
 */
class LedgerService
{
    /**
     * Credit a completed order: everything the buyer paid minus WiBuy's commission. Goods the buyer
     * returned (and got refunded) are left out, and the commission shrinks in proportion. A WiBuy rider's
     * delivery fee is left out too (see creditRider). Safe to call twice.
     */
    public function creditSale(Order $order): ?LedgerEntry
    {
        $kept = Money::sub($order->total, $order->returned_value ?? '0.00');
        $commission = Money::isZero($order->total)
            ? '0.00'
            : bcdiv(bcmul((string) $order->commission, $kept, 6), (string) $order->total, 2);
        $riderJob = $this->riderJobOf($order);

        try {
            return DB::transaction(fn () => LedgerEntry::create([
                'seller_id' => $order->seller_id,
                'order_id' => $order->id,
                'type' => LedgerType::Sale,
                'amount' => Money::sub(Money::sub($kept, $commission), $riderJob->fee ?? '0.00'),
                'description' => "Order {$order->number} completed".match (true) {
                    ! Money::isZero($order->returned_value ?? '0.00') => ' (returned items left out)',
                    $riderJob !== null => ' (delivery fee paid to the WiBuy rider)',
                    default => '',
                },
            ]));
        } catch (UniqueConstraintViolationException) {
            return null; // already credited
        }
    }

    /** Credit the rider who delivered a completed order with its delivery fee. Safe to call twice. */
    public function creditRider(Order $order): ?LedgerEntry
    {
        $job = $this->riderJobOf($order);

        if ($job === null || Money::isZero($job->fee)) {
            return null;
        }

        try {
            return DB::transaction(fn () => LedgerEntry::create([
                'rider_id' => $job->rider_id,
                'order_id' => $order->id,
                'type' => LedgerType::DeliveryFee,
                'amount' => Money::from($job->fee),
                'description' => "Delivery of order {$order->number}",
            ]));
        } catch (UniqueConstraintViolationException) {
            return null; // already credited
        }
    }

    public function debitPayout(Payout $payout): LedgerEntry
    {
        return LedgerEntry::create([
            'seller_id' => $payout->seller_id,
            'rider_id' => $payout->rider_id,
            'payout_id' => $payout->id,
            'type' => LedgerType::Payout,
            'amount' => Money::sub('0.00', $payout->amount),
            'description' => "Payout #{$payout->id} requested",
        ]);
    }

    /** Give the money back to the balance when a payout is rejected or fails. */
    public function reversePayout(Payout $payout, string $why): LedgerEntry
    {
        return LedgerEntry::create([
            'seller_id' => $payout->seller_id,
            'rider_id' => $payout->rider_id,
            'payout_id' => $payout->id,
            'type' => LedgerType::PayoutReversal,
            'amount' => Money::from($payout->amount),
            'description' => "Payout #{$payout->id} {$why}",
        ]);
    }

    public function adjust(Seller $seller, string $amount, string $description): LedgerEntry
    {
        return LedgerEntry::create([
            'seller_id' => $seller->id,
            'type' => LedgerType::Adjustment,
            'amount' => Money::from($amount),
            'description' => $description,
        ]);
    }

    /** Money the seller can ask to have paid out now. */
    public function available(Seller $seller): string
    {
        return Money::from(LedgerEntry::where('seller_id', $seller->id)->sum('amount'));
    }

    /** Money held for orders that are not completed yet (paid, accepted, shipped, delivered, disputed). */
    public function pending(Seller $seller): string
    {
        $row = Order::where('seller_id', $seller->id)->whereIn('status', OrderStatus::funded())
            ->selectRaw('coalesce(sum(total), 0) as total, coalesce(sum(commission), 0) as commission')->first();

        return Money::sub(Money::from($row->total), Money::from($row->commission));
    }

    /** Total of payouts that were actually sent. */
    public function paidOut(Seller $seller): string
    {
        return Money::from(Payout::where('seller_id', $seller->id)->where('status', PayoutStatus::Paid)->sum('amount'));
    }

    /** Payouts asked for or being sent, not finished yet. */
    public function inTransit(Seller $seller): string
    {
        return Money::from(Payout::where('seller_id', $seller->id)
            ->whereIn('status', [PayoutStatus::Requested, PayoutStatus::Processing])->sum('amount'));
    }

    /** @return array{pending: string, available: string, paid_out: string, in_transit: string} */
    public function summary(Seller $seller): array
    {
        return [
            'pending' => $this->pending($seller),
            'available' => $this->available($seller),
            'paid_out' => $this->paidOut($seller),
            'in_transit' => $this->inTransit($seller),
        ];
    }

    /**
     * A rider's money: fees of completed orders (available), fees of delivered orders the buyer has not
     * confirmed yet (pending), and payouts.
     *
     * @return array{pending: string, available: string, paid_out: string, in_transit: string}
     */
    public function riderSummary(User $rider): array
    {
        return [
            'pending' => Money::from(DeliveryJob::where('rider_id', $rider->id)->where('status', DeliveryJob::DELIVERED)
                ->whereHas('order', fn ($q) => $q->whereIn('status', OrderStatus::funded()))->sum('fee')),
            'available' => $this->riderAvailable($rider),
            'paid_out' => Money::from(Payout::where('rider_id', $rider->id)->where('status', PayoutStatus::Paid)->sum('amount')),
            'in_transit' => Money::from(Payout::where('rider_id', $rider->id)
                ->whereIn('status', [PayoutStatus::Requested, PayoutStatus::Processing])->sum('amount')),
        ];
    }

    /** Money the rider can withdraw now. */
    public function riderAvailable(User $rider): string
    {
        return Money::from(LedgerEntry::where('rider_id', $rider->id)->sum('amount'));
    }

    /** The WiBuy rider delivery of an order, when a rider handed it over. */
    private function riderJobOf(Order $order): ?DeliveryJob
    {
        return DeliveryJob::where('order_id', $order->id)->where('status', DeliveryJob::DELIVERED)->whereNotNull('rider_id')->first();
    }
}
