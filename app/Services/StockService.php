<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Every stock change for a product and its variant goes through here. A product with variants keeps
 * its stock equal to the total of its variants, so a variant line moves both. Rows are locked in id
 * order (products, then variants) like checkout does, to avoid deadlocks. Call inside a transaction.
 */
class StockService
{
    /**
     * Units per product and variant, from lines or order items.
     *
     * @param  iterable<array{product_id: int, variant_id: ?int, quantity: int}>  $lines
     * @return array{products: Collection<int, int>, variants: Collection<int, int>}
     */
    public function totals(iterable $lines): array
    {
        $lines = collect($lines);

        return [
            'products' => $lines->groupBy('product_id')->map(fn ($rows) => (int) $rows->sum('quantity')),
            'variants' => $lines->whereNotNull('variant_id')->groupBy('variant_id')->map(fn ($rows) => (int) $rows->sum('quantity')),
        ];
    }

    /**
     * True when every product and variant still has enough units.
     *
     * @param  iterable<array{product_id: int, variant_id: ?int, quantity: int}>  $lines
     */
    public function available(iterable $lines): bool
    {
        ['products' => $products, 'variants' => $variants] = $this->totals($lines);

        $productRows = Product::whereIn('id', $products->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $variantRows = ProductVariant::whereIn('id', $variants->keys())->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return $products->every(fn ($quantity, $id) => isset($productRows[$id]) && $productRows[$id]->stock >= $quantity)
            && $variants->every(fn ($quantity, $id) => isset($variantRows[$id]) && $variantRows[$id]->is_active && $variantRows[$id]->stock >= $quantity);
    }

    /** @param iterable<array{product_id: int, variant_id: ?int, quantity: int}> $lines */
    public function reserve(iterable $lines): void
    {
        $lines = collect($lines);
        $this->move($lines, 'decrement');
        app(LowStockService::class)->check($lines->pluck('product_id')); // "running low" to the shop, once
    }

    /** @param iterable<array{product_id: int, variant_id: ?int, quantity: int}> $lines */
    public function release(iterable $lines): void
    {
        $this->move($lines, 'increment');
    }

    /** Order items (or anything with product_id, variant_id, quantity) as stock lines. */
    public function linesOf(iterable $items, ?callable $quantity = null): Collection
    {
        return collect($items)->map(fn ($item) => [
            'product_id' => (int) $item->product_id,
            'variant_id' => $item->variant_id ? (int) $item->variant_id : null,
            'quantity' => (int) ($quantity ? $quantity($item) : $item->quantity),
        ])->filter(fn ($line) => $line['quantity'] > 0)->values();
    }

    /** After a seller edits variants: the product's stock is the total of its active variants. */
    public function syncProductStock(Product $product): void
    {
        $variants = $product->variants()->active();

        if ($variants->exists()) {
            $product->forceFill(['stock' => (int) $variants->sum('stock')])->save();
        }
    }

    /** @param iterable<array{product_id: int, variant_id: ?int, quantity: int}> $lines */
    private function move(iterable $lines, string $direction): void
    {
        ['products' => $products, 'variants' => $variants] = $this->totals($lines);

        Product::whereIn('id', $products->keys())->orderBy('id')->lockForUpdate()->get()
            ->each(fn (Product $product) => $product->{$direction}('stock', $products[$product->id]));

        ProductVariant::whereIn('id', $variants->keys())->orderBy('id')->lockForUpdate()->get()
            ->each(fn (ProductVariant $variant) => $variant->{$direction}('stock', $variants[$variant->id]));
    }
}
