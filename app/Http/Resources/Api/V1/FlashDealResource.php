<?php

namespace App\Http\Resources\Api\V1;

use App\Models\FlashDeal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FlashDeal */
class FlashDealResource extends JsonResource
{
    /** Deal ids the current person asked to be reminded about. */
    public static array $reminded = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'state' => $this->state, // upcoming | live | ended
            'deal_price' => (string) $this->deal_price,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'max_per_order' => $this->max_per_order,
            'left' => $this->quantity_limit !== null ? max(0, $this->quantity_limit - $this->sold_count) : null,
            'reminded' => in_array($this->id, self::$reminded, true),
            'product' => $this->whenLoaded('product', fn () => (new ProductResource($this->product))->resolve($request)),
        ];
    }
}
