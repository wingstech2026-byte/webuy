<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Enums\PayoutStatus;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Seller;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A seller's money. Every credit and debit is a ledger row and rows are never changed.
 *   available = sum of the ledger (completed sales minus payouts)
 *   pending   = what the seller will earn from orders still in progress
 *   paid out  = payouts that were sent
 */
class LedgerService
{
    /** Credit a completed order: everything the buyer paid minus WiBuy's commission. Safe to call twice. */
    public function creditSale(Order $order): ?LedgerEntry
    {
        try {
            return DB::transaction(fn () => LedgerEntry::create([
                'seller_id' => $order->seller_id,
                'order_id' => $order->id,
                'type' => LedgerType::Sale,
                'amount' => Money::sub($order->total, $order->commission),
                'description' => "Order {$order->number} completed",
            ]));
        } catch (UniqueConstraintViolationException) {
            return null; // already credited
        }
    }

    public function debitPayout(Payout $payout): LedgerEntry
    {
        return LedgerEntry::create([
            'seller_id' => $payout->seller_id,
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
}
