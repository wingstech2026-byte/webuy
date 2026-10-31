<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/** Saves a product's options from the seller form, keeping order history intact. */
class VariantService
{
    public function __construct(private StockService $stock) {}

    /**
     * Rows match existing options by id, then by name. Options left out are removed, or switched off
     * when someone already ordered them. With active options, the product's stock is their total.
     *
     * @param  list<array{id: ?int, name: string, price: ?string, stock: int}>  $rows
     */
    public function sync(Product $product, array $rows): void
    {
        DB::transaction(function () use ($product, $rows) {
            $existing = $product->variants()->lockForUpdate()->get();
            $kept = [];

            foreach ($rows as $position => $row) {
                $variant = ($row['id'] ? $existing->firstWhere('id', $row['id']) : null)
                    ?? $existing->first(fn (ProductVariant $v) => mb_strtolower($v->name) === mb_strtolower($row['name']) && ! in_array($v->id, $kept, true))
                    ?? new ProductVariant(['product_id' => $product->id]);

                $variant->fill([
                    'name' => $row['name'],
                    'price' => $row['price'],
                    'stock' => $row['stock'],
                    'position' => $position,
                    'is_active' => true,
                ])->save();

                $kept[] = $variant->id;
            }

            foreach ($existing->whereNotIn('id', $kept) as $gone) {
                OrderItem::where('variant_id', $gone->id)->exists()
                    ? $gone->forceFill(['is_active' => false])->save()
                    : $gone->delete();
            }

            $this->stock->syncProductStock($product);
        });
    }
}
