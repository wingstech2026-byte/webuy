<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A seller's answer to an RFQ. The price is locked until valid_until. */
class RfqQuote extends Model
{
    protected $fillable = ['rfq_id', 'seller_id', 'product_id', 'unit_price', 'quantity', 'lead_time_days', 'note', 'valid_until', 'status'];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'valid_until' => 'datetime',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isValid(): bool
    {
        return $this->status === 'submitted' && $this->valid_until->isFuture();
    }

    public function getItemsTotalAttribute(): string
    {
        return Money::mul($this->unit_price, $this->quantity);
    }
}
