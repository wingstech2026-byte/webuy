<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CartItem;
use App\Models\Product;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cart line: the array built by CartService::lines() (item, product, quantity, problem).
 *
 * @property array{item: CartItem, product: Product, quantity: int, problem: ?string} $resource
 */
class CartLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource['product'];
        $quantity = $this->resource['quantity'];
        $variant = $this->resource['variant'] ?? null;
        $unitPrice = $variant?->price !== null ? Money::from($variant->price) : $product->unitPriceFor($quantity);

        return [
            'id' => $this->resource['item']->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => Money::mul($unitPrice, $quantity),
            'is_wholesale_price' => $unitPrice !== (string) $product->price,
            'problem' => $this->resource['problem'],
            'variant' => $variant ? ['id' => $variant->id, 'name' => $variant->name] : null,
            'product' => new ProductResource($product),
        ];
    }
}
