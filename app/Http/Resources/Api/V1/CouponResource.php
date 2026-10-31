<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'source' => $this->source,
            'amount' => $this->amount !== null ? (string) $this->amount : null,
            'percent' => $this->percent !== null ? (string) $this->percent : null,
            'min_spend' => (string) $this->min_spend,
            'product_slug' => $this->product?->slug,
            'app_only' => $this->app_only,
            'status' => $this->status,
            'unlock_hint' => $this->unlock_hint,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
