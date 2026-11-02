<?php

namespace App\Services;

use App\Models\Product;
use App\Models\WishlistItem;
use App\Support\Money;

/**
 * Tells people who saved a product when its price drops or when it is back in stock (in the app's
 * notifications, and as a phone notification when push is set up). Each drop or restock is told once.
 */
class WishlistAlertService
{
    public function __construct(private NotificationService $notifications) {}

    /** After a seller saved the product. Returns how many people were told. */
    public function afterChange(Product $product): int
    {
        if (! $product->isListed()) {
            return 0;
        }

        $told = 0;
        $inStock = $product->stock > 0;

        foreach (WishlistItem::where('product_id', $product->id)->with('user')->get() as $item) {
            $messages = [];

            if ($item->alert_price !== null && Money::cmp($product->price, $item->alert_price) < 0) {
                $messages[] = ['💸 Price dropped', "{$product->title} is now ".money($product->price).' (was '.money($item->alert_price).').'];
            }

            if ($inStock && ! $item->alert_in_stock) {
                $messages[] = ['📦 Back in stock', "{$product->title} is back in stock. Get it before it sells out."];
            }

            foreach ($messages as [$title, $body]) {
                $this->notifications->notify($item->user, $title, $body, route('products.show', $product));
            }

            $told += $messages === [] ? 0 : 1;
            // Remember what they now know: the next drop is counted from this price.
            $item->forceFill(['alert_price' => $product->price, 'alert_in_stock' => $inStock])->save();
        }

        return $told;
    }
}
