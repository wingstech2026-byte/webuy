<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Membership */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status, // pending | active | failed
            'price' => (string) $this->price,
            'payer_phone' => $this->payer_phone,
            'operator_label' => $this->operator->label(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'can_simulate' => config('payments.gateway') === 'fake' && ! app()->isProduction(),
        ];
    }
}
