<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductPriceTracking
{
    /** @return Collection<int, array{recorded_at: string, price: string}> */
    public function history(Product $product): Collection
    {
        $history = DB::table('product_price_history')->where('product_id', $product->id);
        $since = now()->subDays(90);
        $openingPrice = (clone $history)->where('recorded_at', '<', $since)->latest('recorded_at')->latest('id')->value('price');

        return collect($openingPrice === null ? [] : [
            ['recorded_at' => $since->toIso8601String(), 'price' => (string) $openingPrice],
        ])->concat($history->where('recorded_at', '>=', $since)->orderBy('recorded_at')->orderBy('id')
            ->get(['recorded_at', 'price'])->map(fn ($point) => ['recorded_at' => Carbon::parse($point->recorded_at)->toIso8601String(), 'price' => (string) $point->price]));
    }

    public function target(Product $product, ?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $target = DB::table('product_price_alerts')->where('user_id', $user->id)->where('product_id', $product->id)->value('target');

        return $target === null ? null : (string) $target;
    }

    public function saveAlert(Request $request, Product $product): ?string
    {
        $data = $request->validate(['target' => ['present', 'nullable', 'decimal:0,2', 'min:0.01', 'max:999999999999.99']]);
        $key = ['user_id' => $request->user()->id, 'product_id' => $product->id];

        if ($data['target'] === null) {
            DB::table('product_price_alerts')->where($key)->delete();

            return null;
        }

        DB::table('product_price_alerts')->upsert([$key + ['target' => $data['target']]], ['user_id', 'product_id'], ['target']);

        return (string) $data['target'];
    }
}
