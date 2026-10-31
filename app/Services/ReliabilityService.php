<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\SellerStatus;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Seller;
use App\Support\RewardSettings;

/**
 * Delivery reliability score (0-100) from the last 90 days: on-time shipping (60%), orders the seller
 * did not cancel or let expire (25%) and orders without a problem report (15%). Shown on the shop page and
 * used in search ranking. Sellers with no orders in the period have no score yet ("New").
 */
class ReliabilityService
{
    public function score(Seller $seller): ?int
    {
        $orders = Order::where('seller_id', $seller->id)->where('paid_at', '>=', now()->subDays(90))
            ->with('items.product')->get();

        if ($orders->isEmpty()) {
            return null;
        }

        $shipDays = RewardSettings::int('reliability_ship_days');

        $shipped = $orders->whereNotNull('shipped_at');
        $onTime = $shipped->filter(function (Order $order) use ($shipDays) {
            $lead = (int) $order->items->max(fn ($item) => $item->product?->lead_time_days ?? 0);

            return $order->shipped_at->lte($order->paid_at->copy()->addDays($shipDays + $lead));
        })->count();

        $failed = $orders->filter(fn (Order $order) => $order->status === OrderStatus::Rejected
            || ($order->status === OrderStatus::Refunded && $order->accepted_at === null))->count();

        $disputed = Dispute::whereIn('order_id', $orders->pluck('id'))->distinct('order_id')->count('order_id');

        $total = $orders->count();
        $onTimeRate = $shipped->isEmpty() ? ($failed > 0 ? 0 : 1) : $onTime / $shipped->count();

        return (int) round(100 * (0.6 * $onTimeRate + 0.25 * (1 - $failed / $total) + 0.15 * (1 - $disputed / $total)));
    }

    public function refresh(Seller $seller): ?int
    {
        $score = $this->score($seller);
        $seller->forceFill(['reliability_score' => $score, 'reliability_updated_at' => now()])->save();

        return $score;
    }

    public function refreshAll(): int
    {
        $count = 0;

        Seller::where('status', SellerStatus::Approved)->each(function (Seller $seller) use (&$count) {
            $this->refresh($seller);
            $count++;
        });

        return $count;
    }
}
