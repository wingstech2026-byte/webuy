<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'display_phone' => $this->display_phone,
            'is_seller' => $this->isSeller(),
            'is_admin' => $this->is_admin,
            'is_agent' => (bool) $this->is_agent,
            'is_rider' => (bool) $this->is_rider,
            'business_status' => $this->businessProfile?->status, // null | pending | verified | rejected
        ];
    }
}
