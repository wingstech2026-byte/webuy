<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Image;
use App\Models\Product;
use App\Models\TrialCampaign;
use App\Services\RiskService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A product. Money is sent as strings with 2 decimals ("650.00") so no precision is lost.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** Add the long fields (description, all pictures) used on the product screen. */
    public bool $detailed = false;

    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isBusiness = (bool) $request->user('sanctum')?->isVerifiedBusiness();
        $allTiers = $this->relationLoaded('tiers') ? $this->tiers : collect();
        // Business-only wholesale prices are shown to verified business buyers only.
        $tiers = $allTiers->filter(fn ($tier) => $isBusiness || ! $tier->business_only);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'price' => (string) $this->price,
            'compare_at_price' => $this->compare_at_price !== null ? (string) $this->compare_at_price : null,
            'discount_percent' => $this->discount_percent,
            'lowest_price' => $this->lowest_price,
            'unit' => $this->unit,
            'stock' => $this->stock,
            'min_order_quantity' => $this->min_order_quantity,
            'condition' => $this->condition->value,
            'genuine_guarantee' => (bool) $this->genuine_guarantee,
            'is_sponsored' => $this->isSponsored(), // shown as "Sponsored"
            'sponsored_until' => $this->sponsored_until?->toIso8601String(),
            'video_url' => $this->video_url,
            'video_seconds' => $this->video_seconds,
            'is_deal' => $this->is_deal,
            'rating_avg' => (float) $this->rating_avg,
            'rating_count' => $this->rating_count,
            'sold_count' => $this->sold_count,
            'thumb_url' => $this->cover_thumb_url,
            'tiers' => $tiers->map(fn ($tier): array => [
                'min_quantity' => $tier->min_quantity,
                'unit_price' => (string) $tier->unit_price,
            ])->values(),
            'seller' => new SellerResource($this->whenLoaded('seller')),
            // Options such as size or colour; the buyer must pick one when the list is not empty.
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->where('is_active', true)->map(fn ($variant): array => [
                'id' => $variant->id,
                'name' => $variant->name,
                'price' => $variant->price !== null ? (string) $variant->price : null,
                'stock' => $variant->stock,
            ])->values()),
            'web_url' => route('products.show', $this->resource),
            'member_price' => $this->member_price !== null ? (string) $this->member_price : null,
            'has_business_prices' => $allTiers->contains('business_only', true),
            'lead_time_days' => $this->lead_time_days, // "Ready in X days" for made-to-order items
            'sample_price' => $this->sample_price !== null ? (string) $this->sample_price : null,
            'flash_deal' => $this->flashDealArray(),
            $this->mergeWhen($this->detailed, fn (): array => [
                'description' => $this->description,
                'category' => $this->category ? ['name' => $this->category->name, 'slug' => $this->category->slug] : null,
                'price_cut_campaign_id' => $this->priceCutCampaigns()->open()->value('id'),
                'risk_warnings' => app(RiskService::class)->productWarnings($this->resource),
                'trial_campaign_id' => TrialCampaign::open()->where('product_id', $this->id)->value('id'),
                'images' => $this->images->where('status', 'ready')->values()->map(fn (Image $image): array => [
                    'url' => $image->url,
                    'thumb_url' => $image->thumb_url,
                ]),
            ]),
        ];
    }

    /** The lightning deal running now (or later today) for this product. */
    private function flashDealArray(): ?array
    {
        $deal = $this->resource->flashDeals()->where('ends_at', '>', now())->where('starts_at', '<', today()->addDay())
            ->orderBy('starts_at')->first();

        if (! $deal || $deal->state === 'ended') {
            return null;
        }

        return [
            'id' => $deal->id,
            'state' => $deal->state,
            'deal_price' => (string) $deal->deal_price,
            'starts_at' => $deal->starts_at->toIso8601String(),
            'ends_at' => $deal->ends_at->toIso8601String(),
            'max_per_order' => $deal->max_per_order,
        ];
    }
}
