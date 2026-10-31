<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Review */
class ReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'rating' => $this->rating,
            'comment' => $this->comment,
            'buyer' => $this->buyer?->first_name,
            'is_free_trial' => (bool) $this->is_free_trial, // shown as "Free trial" so other buyers know
            'photos' => $this->images->where('status', 'ready')->map(fn ($image) => $image->thumb_url)->values(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
