<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendProductPriceAlerts extends Command
{
    protected $signature = 'wibuy:price-alerts';

    protected $description = 'Notify buyers when a listed product drops below their target';

    public function handle(NotificationService $notifications): int
    {
        Product::listed()->whereExists(function ($query): void {
            $query->select('id')->from('product_price_alerts')
                ->whereColumn('product_id', 'products.id')->whereColumn('target', '>', 'products.price');
        })->eachById(function (Product $product) use ($notifications): void {
            DB::table('product_price_alerts')->where('product_id', $product->id)
                ->where('target', '>', $product->price)->eachById(function ($alert) use ($product, $notifications): void {
                    DB::transaction(function () use ($alert, $product, $notifications): void {
                        $removed = DB::table('product_price_alerts')->where('id', $alert->id)
                            ->where('target', $alert->target)->delete();
                        $buyer = $removed ? User::find($alert->user_id) : null;

                        if ($buyer) {
                            $notifications->notify($buyer, 'Price alert', "{$product->title} is now Le {$product->price}.", "/p/{$product->slug}");
                        }
                    });
                });
        });

        return self::SUCCESS;
    }
}
