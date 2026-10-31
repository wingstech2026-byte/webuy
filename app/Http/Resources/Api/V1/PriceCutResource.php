<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PriceCut;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PriceCut */
class PriceCutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $campaign = $this->campaign;
        $total = Money::sub($this->start_price, $campaign->floor_price);
        $done = Money::sub($this->start_price, $this->current_price);

        return [
            'token' => $this->token,
            'status' => $this->isActive() || $this->status !== PriceCut::ACTIVE ? $this->status : PriceCut::EXPIRED,
            'start_price' => (string) $this->start_price,
            'current_price' => (string) $this->current_price,
            'floor_price' => (string) $campaign->floor_price,
            'progress_percent' => Money::isZero($total) ? 100 : (int) min(100, floor((float) $done * 100 / (float) $total)),
            'expires_at' => $this->expires_at->toIso8601String(),
            'is_mine' => $request->user()?->id === $this->user_id,
            'owner' => $this->user->first_name,
            'helpers' => $this->helpers->map(fn ($h) => ['name' => $h->helper->first_name, 'amount' => (string) $h->amount])->values(),
            'coupon_id' => $this->coupon_id,
            'share_url' => $this->url,
            'product' => (new ProductResource($campaign->product))->resolve($request),
        ];
    }
}
