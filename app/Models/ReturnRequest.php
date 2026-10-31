<?php

namespace App\Models;

use Database\Factories\ReturnRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A buyer sending items back. See ReturnService for the steps. */
class ReturnRequest extends Model
{
    /** @use HasFactory<ReturnRequestFactory> */
    use HasFactory;

    public const REQUESTED = 'requested';

    public const ACCEPTED = 'accepted';

    public const RECEIVED = 'received';

    public const ESCALATED = 'escalated';

    public const CANCELLED = 'cancelled';

    /** Seller-fault reasons only: a buyer who changed their mind agrees that with the seller directly. */
    public const REASONS = [
        'damaged' => 'Arrived damaged or broken',
        'wrong_item' => 'Wrong item, size or colour',
        'not_as_described' => 'Not as described or shown',
        'missing_parts' => 'Parts or items missing',
        'does_not_work' => 'Does not work',
    ];

    protected $fillable = [
        'order_id', 'buyer_id', 'seller_id', 'reason', 'description', 'items', 'is_full', 'returned_value', 'refund_amount',
        'status', 'return_instructions', 'seller_note', 'respond_by', 'send_back_by', 'accepted_at', 'received_at', 'dispute_id',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'is_full' => 'boolean',
            'returned_value' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'respond_by' => 'datetime',
            'send_back_by' => 'datetime',
            'accepted_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function photos(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->orderBy('position');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::REQUESTED => 'Waiting for the seller',
            self::ACCEPTED => 'Accepted: send the item back',
            self::RECEIVED => 'Item received: refund sent',
            self::ESCALATED => 'WiBuy is deciding',
            default => 'Cancelled',
        };
    }

    /** "2 × Rice 50kg, 1 × Oil 5L" from the order's items. */
    public function itemsLine(): string
    {
        $titles = $this->order->items->keyBy('id');

        return collect($this->items)->map(fn ($i) => $i['quantity'].' × '.($titles[$i['order_item_id']]->title ?? '?'))->implode(', ');
    }
}
