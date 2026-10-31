<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Rfq;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Rfq */
class RfqResource extends JsonResource
{
    /** Side-by-side quotes from RfqService::compare(), when shown. */
    public ?array $comparison = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'district' => $this->district,
            'target_price' => $this->target_price !== null ? (string) $this->target_price : null,
            'needed_by' => $this->needed_by?->toDateString(),
            'status' => $this->status,
            'quotes_count' => $this->quotes_count ?? $this->quotes()->count(),
            'created_at' => $this->created_at?->toIso8601String(),
            'quotes' => $this->comparison === null ? null : array_map(fn (array $row) => [
                'id' => $row['quote']->id,
                'seller' => (new SellerResource($row['quote']->seller))->resolve($request),
                'product' => ['slug' => $row['quote']->product->slug, 'title' => $row['quote']->product->title],
                'unit_price' => (string) $row['quote']->unit_price,
                'quantity' => $row['quote']->quantity,
                'items_total' => $row['items_total'],
                'delivery_fee' => $row['delivery_fee'],
                'total' => $row['total'],
                'can_deliver' => $row['can_deliver'],
                'lead_time_days' => $row['days'],
                'note' => $row['quote']->note,
                'valid_until' => $row['quote']->valid_until->toIso8601String(),
                'status' => $row['quote']->status,
                'is_valid' => $row['is_valid'],
            ], $this->comparison),
        ];
    }
}
