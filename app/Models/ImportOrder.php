<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A WiBuy Global import: deposit now, balance on arrival, tracked from the factory to Freetown. */
class ImportOrder extends Model
{
    /** status => what the buyer sees, in order. */
    public const STEPS = [
        'awaiting_deposit' => 'Waiting for your deposit',
        'ordered' => 'Ordered',
        'at_factory' => 'At the factory',
        'shipped' => 'Shipped',
        'at_port' => 'At Freetown port',
        'customs_cleared' => 'Customs cleared',
        'ready_for_pickup' => 'Ready for pickup (pay the balance)',
        'completed' => 'Collected',
    ];

    protected $fillable = [
        'number', 'user_id', 'import_product_id', 'quantity', 'shipping', 'unit_price', 'total', 'deposit_amount',
        'balance_amount', 'status', 'estimated_arrival', 'deposit_paid_at', 'balance_paid_at', 'timeline',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'estimated_arrival' => 'date',
            'deposit_paid_at' => 'datetime',
            'balance_paid_at' => 'datetime',
            'timeline' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ImportProduct::class, 'import_product_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STEPS[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }
}
