<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Support\Facades\DB;

/**
 * Buyer protection. The buyer always gets a WiBuy coupon (never cash); the same amount is taken from the
 * seller's balance, so it costs the shop that caused it.
 *  - Price protection: the price drops within a few days of a purchase, the buyer gets the difference.
 *  - Late shipping: an accepted order is not shipped in time, the buyer gets a small coupon (once).
 *  - Genuine guarantee: goods sold as genuine turn out fake (decided in a problem report), the buyer gets a
 *    coupon on top of the refund (once).
 */
class BuyerProtectionService
{
    public function __construct(
        private CouponService $coupons,
        private LedgerService $ledger,
        private NotificationService $notifications,
    ) {}

    /** After a seller changes a product's price (or an option's price). Returns how many buyers got a coupon. */
    public function onPriceChange(Product $product): int
    {
        $days = RewardSettings::int('price_protection_days');

        if ($days < 1) {
            return 0;
        }

        $product->loadMissing('variants');
        $given = 0;

        $items = OrderItem::where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->where('paid_at', '>=', now()->subDays($days))
                ->whereIn('status', [...OrderStatus::funded(), OrderStatus::Completed]))
            ->pluck('id');

        foreach ($items as $itemId) {
            $given += DB::transaction(function () use ($itemId, $product) {
                $item = OrderItem::lockForUpdate()->with('order.buyer', 'order.seller')->find($itemId);
                $variant = $item->variant_id ? $product->variants->firstWhere('id', $item->variant_id) : null;
                $nowPrice = $variant?->price ?? $product->price;

                // What the buyer is owed in total for this line; earlier drops were already given.
                $owed = Money::cmp($item->unit_price, $nowPrice) > 0 ? Money::mul(Money::sub($item->unit_price, $nowPrice), $item->quantity) : '0.00';
                $extra = Money::sub($owed, $item->price_protection_amount);

                if (Money::cmp($extra, '0') <= 0) {
                    return 0;
                }

                $item->forceFill(['price_protection_amount' => $owed])->save();
                $order = $item->order;
                $this->give($order->buyer, $extra, 'price_protection', "Price drop on {$item->display_title}");
                $this->ledger->chargeSeller($order->seller, $extra, LedgerType::PriceProtection, "Price protection: {$item->display_title} got cheaper after order {$order->number}");
                $this->notifications->notify($order->buyer, 'The price dropped: you get the difference',
                    "{$item->display_title} is now ".money($nowPrice).'. We gave you a '.money($extra).' coupon for the difference.');

                return 1;
            });
        }

        return $given;
    }

    /** Accepted orders not shipped in time: one coupon per order. Returns how many were compensated. */
    public function compensateLateShipping(): int
    {
        $hours = RewardSettings::int('ship_within_hours');
        $count = 0;

        $late = Order::where('status', OrderStatus::Accepted)->where('accepted_at', '<=', now()->subHours($hours))
            ->where('late_compensation', 0)->pluck('id');

        foreach ($late as $orderId) {
            $count += DB::transaction(function () use ($orderId) {
                $order = Order::lockForUpdate()->with('buyer', 'seller')->find($orderId);

                if ($order->status !== OrderStatus::Accepted || ! Money::isZero($order->late_compensation)) {
                    return 0;
                }

                $amount = Money::percentOf($order->subtotal, RewardSettings::int('late_ship_coupon_percent'));
                $amount = Money::cmp($amount, RewardSettings::money('late_ship_coupon_max')) > 0 ? RewardSettings::money('late_ship_coupon_max') : $amount;

                if (Money::cmp($amount, '0') <= 0) {
                    return 0;
                }

                $this->ledger->chargeSeller($order->seller, $amount, LedgerType::LateShipping, "Late shipping: order {$order->number} was not sent in time", $order);

                $order->forceFill(['late_compensation' => $amount])->save();
                $this->give($order->buyer, $amount, 'late_shipping', "Sorry for the wait (order {$order->number})");
                $this->notifications->notify($order->buyer, 'Sorry, your order is late',
                    "The shop has not sent order {$order->number} on time. Here is a ".money($amount).' coupon. You can still report a problem if it does not come.');

                return 1;
            });
        }

        return $count;
    }

    /**
     * A problem report about fake goods was decided for the buyer: extra coupon for the items sold with a
     * genuine guarantee (on top of the refund). Called inside the dispute decision; once per order.
     */
    public function compensateFakeGoods(Order $order): ?Coupon
    {
        $guaranteed = $order->items()->where('genuine_guarantee', true)->get();

        if ($guaranteed->isEmpty()) {
            return null;
        }

        $amount = Money::percentOf(Money::sum($guaranteed->pluck('line_total')), RewardSettings::int('genuine_compensation_percent'));

        if (Money::cmp($amount, '0') <= 0) {
            return null;
        }

        // Checked first rather than caught: a failed insert would break the dispute's transaction on PostgreSQL.
        if (LedgerEntry::where('order_id', $order->id)->where('type', LedgerType::GenuineGuarantee)->exists()) {
            return null;
        }

        $this->ledger->chargeSeller($order->seller, $amount, LedgerType::GenuineGuarantee, "Genuine guarantee: fake goods in order {$order->number}", $order);

        return $this->give($order->buyer, $amount, 'genuine_guarantee', "Genuine guarantee (order {$order->number})");
    }

    private function give(User $buyer, string $amount, string $source, string $title): Coupon
    {
        return $this->coupons->issue($buyer, [
            'title' => $title,
            'source' => $source,
            'amount' => $amount,
            'expires_at' => now()->addDays(max(1, RewardSettings::int('protection_coupon_days'))),
        ]);
    }
}
