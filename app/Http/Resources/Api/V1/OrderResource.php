<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isBuyer = $request->user()?->id === $this->buyer_id;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->statusLabel(),
            'subtotal' => (string) $this->subtotal,
            'delivery_fee' => (string) $this->delivery_fee,
            'total' => (string) $this->total,
            'discount' => (string) $this->discount,
            'amount_paid' => $this->amount_paid,
            'gift_note' => $this->gift_note,
            'milestones' => $this->milestones->map(fn ($m) => [
                'id' => $m->id, 'label' => $m->label, 'percent' => $m->percent, 'amount' => (string) $m->amount,
                'status' => $m->status, 'paid_at' => $m->paid_at?->toIso8601String(),
            ])->values(),
            'can_pay_balance' => $isBuyer && $this->status === OrderStatus::Accepted && $this->milestones->contains('status', 'pending')
                && (! $this->inspection || $this->inspection->status === 'passed'),
            'can_request_inspection' => $isBuyer && ! $this->inspection && $this->milestones->contains('status', 'pending')
                && in_array($this->status, [OrderStatus::Paid, OrderStatus::Accepted], true),
            'inspection' => $this->inspection ? [
                'status' => $this->inspection->status, 'fee' => (string) $this->inspection->fee, 'report' => $this->inspection->report,
                'photos' => $this->inspection->photos->where('status', 'ready')->map(fn ($p) => $p->thumb_url)->values(),
            ] : null,
            'rfq_quote_id' => $this->rfq_quote_id,
            'delivery_method' => $this->delivery_method->value,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'district' => $this->district,
            'address' => $this->address,
            'delivery_note' => $this->delivery_note,
            'rejection_reason' => $this->rejection_reason,
            'refund_reason' => $this->refund_reason,
            'seller' => new SellerResource($this->whenLoaded('seller')),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'product_slug' => $item->product?->slug,
                'title' => $item->title,
                'variant_name' => $item->variant_name,
                'unit' => $item->unit,
                'unit_price' => (string) $item->unit_price,
                'quantity' => $item->quantity,
                'line_total' => (string) $item->line_total,
                'thumb_url' => $item->thumb_url,
                'reviewed' => $item->relationLoaded('review') ? $item->review !== null : null,
            ])),
            // The steps of the order, for the timeline.
            'timeline' => [
                ['label' => 'Ordered and paid', 'at' => $this->paid_at?->toIso8601String()],
                ['label' => 'Seller accepted', 'at' => $this->accepted_at?->toIso8601String()],
                ['label' => $this->shipped_label, 'at' => $this->shipped_at?->toIso8601String()],
                ['label' => 'Delivered', 'at' => $this->delivered_at?->toIso8601String()],
                ['label' => 'Completed', 'at' => $this->completed_at?->toIso8601String()],
            ],
            // A WiBuy rider delivery: stage, rider and the rider's steps (no location tracking).
            'rider_delivery' => $this->relationLoaded('deliveryJob') ? $this->riderDelivery() : null,
            // Collect at a WiBuy station: where, and whether it is there yet (the code comes by SMS).
            'collect_station' => $this->collect_station_id && $this->collectStation ? [
                'name' => $this->collectStation->name, 'address' => $this->collectStation->address, 'district' => $this->collectStation->district,
                'opening_hours' => $this->collectStation->opening_hours, 'ready' => $this->at_collect_station_at !== null,
            ] : null,
            'can_confirm_delivery' => $isBuyer && in_array($this->status, [OrderStatus::Shipped, OrderStatus::Delivered], true),
            'can_report_problem' => $isBuyer && in_array($this->status, OrderStatus::disputable(), true),
            'can_return' => $isBuyer && $this->status === OrderStatus::Delivered && $this->returnRequest === null,
            'return' => $this->returnRequest ? [
                'id' => $this->returnRequest->id,
                'status' => $this->returnRequest->status,
                'status_label' => $this->returnRequest->statusLabel(),
                'items' => $this->returnRequest->itemsLine(),
                'reason' => ReturnRequest::REASONS[$this->returnRequest->reason] ?? $this->returnRequest->reason,
                'refund_amount' => (string) $this->returnRequest->refund_amount,
                'is_full' => $this->returnRequest->is_full,
                'return_instructions' => $this->returnRequest->return_instructions,
                'seller_note' => $this->returnRequest->seller_note,
                'respond_by' => $this->returnRequest->respond_by?->toIso8601String(),
            ] : null,
            'can_review' => $isBuyer && $this->status === OrderStatus::Completed
                && ($this->relationLoaded('sellerReview') ? $this->sellerReview === null : true),
            // Disputes (with photos) are handled on the website for now.
            'report_problem_url' => route('disputes.create', $this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
