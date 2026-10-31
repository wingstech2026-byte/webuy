<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\PaymentStatus;
use App\Models\Checkout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A checkout: one mobile money payment covering one order per seller. The app polls it
 * every 5 seconds while "payment" is "waiting".
 *
 * @mixin Checkout
 */
class CheckoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deposit = $this->relationLoaded('latestDeposit') ? $this->latestDeposit : null;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            // waiting | paid | failed | cancelled: what the payment screen should show.
            'payment' => match (true) {
                $this->status->value === 'paid' => 'paid',
                $deposit?->status === PaymentStatus::Failed => 'failed',
                $this->status->value === 'cancelled' => 'cancelled',
                default => 'waiting',
            },
            'failure_reason' => $deposit?->raw_response['reason'] ?? null,
            'total' => (string) $this->total, // what the buyer pays by mobile money
            'discount' => (string) $this->discount,
            'payer_phone' => $this->payer_phone,
            'operator' => $this->operator->value,
            'operator_label' => $this->operator->label(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            // Development only: the app shows "Simulate approve / decline" buttons.
            'can_simulate' => config('payments.gateway') === 'fake' && ! app()->isProduction(),
            'orders' => OrderResource::collection($this->whenLoaded('orders')),
        ];
    }
}
