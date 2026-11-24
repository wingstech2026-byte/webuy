<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductStockAlerts
{
    public function __construct(private NotificationService $notifications) {}

    public function hasStock(Product $product): bool
    {
        if ($product->stock < $product->min_order_quantity) {
            return false;
        }

        $product->loadMissing('variants');
        $options = $product->variants->where('is_active', true);

        return $options->isEmpty() || $options->contains(fn ($option) => $option->stock >= $product->min_order_quantity);
    }

    public function subscribed(Product $product, ?User $user): bool
    {
        return $user !== null && DB::table('product_stock_alerts')->where([
            'product_id' => $product->id, 'user_id' => $user->id,
        ])->exists();
    }

    public function update(Product $product, User $user, bool $subscribe): void
    {
        $key = ['product_id' => $product->id, 'user_id' => $user->id];

        if (! $subscribe) {
            DB::table('product_stock_alerts')->where($key)->delete();

            return;
        }

        if ($this->hasStock($product) && ! $this->subscribed($product, $user)) {
            throw ValidationException::withMessages(['subscribed' => 'This product is already in stock.']);
        }

        DB::table('product_stock_alerts')->insertOrIgnore($key);
    }

    public function sendAvailable(): void
    {
        Product::listed()->whereColumn('stock', '>=', 'min_order_quantity')
            ->whereExists(fn ($query) => $query->select('id')->from('product_stock_alerts')->whereColumn('product_id', 'products.id'))
            ->with(['variants', 'seller'])->eachById(function (Product $product): void {
                if (! $this->hasStock($product) || $product->seller->isOnHoliday()) {
                    return;
                }

                DB::table('product_stock_alerts')->where('product_id', $product->id)->eachById(function ($alert) use ($product): void {
                    DB::transaction(function () use ($alert, $product): void {
                        if (DB::table('product_stock_alerts')->where('id', $alert->id)->delete() && $buyer = User::find($alert->user_id)) {
                            $this->notifications->notify($buyer, 'Back in stock', "{$product->title} is available again.", "/p/{$product->slug}");
                        }
                    });
                });
            });
    }
}
