<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TrialCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TrialCampaign */
class TrialCampaignResource extends JsonResource
{
    /** campaign id => the current person's application status */
    public static array $mine = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trial_price' => (string) $this->trial_price,
            'slots' => $this->slots,
            'applied_count' => $this->applications_count ?? $this->applications()->count(),
            'apply_until' => $this->apply_until->toIso8601String(),
            'review_days' => $this->review_days,
            'is_open' => $this->isOpen(),
            'my_status' => self::$mine[$this->id] ?? null, // applied | selected | rejected | reviewed | overdue
            'review_url' => route('trials.review', $this),
            'product' => (new ProductResource($this->product))->resolve($request),
        ];
    }
}
