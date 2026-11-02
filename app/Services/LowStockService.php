<?php

namespace App\Services;

use App\Models\Product;

/**
 * Tells a shop when a product is running low (5 or fewer left), once, so it can restock before it sells out.
 * Told again only after it has been restocked above that.
 */
class LowStockService
{
    public const THRESHOLD = 5;

    public function __construct(private NotificationService $notifications, private SmsService $sms) {}

    /** @param iterable<int> $productIds */
    public function check(iterable $productIds): void
    {
        Product::whereIn('id', collect($productIds)->unique()->all())->where('stock', '<=', self::THRESHOLD)
            ->whereNull('low_stock_notified_at')->with('seller.user')->get()
            ->each(function (Product $product) {
                $product->forceFill(['low_stock_notified_at' => now()])->saveQuietly();
                $text = $product->stock < 1 ? "{$product->title} is sold out." : "Only {$product->stock} left of {$product->title}.";
                $this->notifications->notify($product->seller->user, '📉 Running low', $text.' Restock it so buyers can keep ordering.', route('seller.products.edit', $product));
                $this->sms->send($product->seller->user->phone, "WiBuy: {$text} Restock it in your shop so buyers can keep ordering.");
            });
    }
}
