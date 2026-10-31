<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Seller */
class SellerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $logo = $this->relationLoaded('logo') ? $this->logo : null;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id, // to start a chat with the seller
            'slug' => $this->slug,
            'shop_name' => $this->shop_name,
            'description' => $this->description,
            'district' => $this->district,
            'area' => $this->area,
            'location' => $this->location,
            'pickup_address' => $this->pickup_address,
            'is_verified' => $this->is_verified,
            'offers_delivery' => $this->offers_delivery,
            'offers_pickup' => $this->offers_pickup,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'reliability_score' => $this->reliability_score, // 0-100 over 90 days; null = new shop
            'logo_url' => $logo?->isReady() ? $logo->thumb_url : null,
            'web_url' => route('shops.show', $this->resource),
        ];
    }
}
