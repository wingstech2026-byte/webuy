<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A lightning deal: a lower price on one product for a short time (e.g. 2 hours). */
class FlashDeal extends Model
{
    protected $fillable = ['product_id', 'deal_price', 'starts_at', 'ends_at', 'max_per_order', 'quantity_limit', 'sold_count'];

    protected function casts(): array
    {
        return [
            'deal_price' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(FlashDealReminder::class);
    }

    public function scopeLive(Builder $query): void
    {
        $query->where('starts_at', '<=', now())->where('ends_at', '>', now())
            ->where(fn (Builder $q) => $q->whereNull('quantity_limit')->orWhereColumn('sold_count', '<', 'quantity_limit'));
    }

    public function isLive(): bool
    {
        return $this->starts_at->lte(now()) && $this->ends_at->isFuture()
            && ($this->quantity_limit === null || $this->sold_count < $this->quantity_limit);
    }

    public function getStateAttribute(): string
    {
        return match (true) {
            $this->ends_at->isPast() || ($this->quantity_limit !== null && $this->sold_count >= $this->quantity_limit) => 'ended',
            $this->starts_at->isFuture() => 'upcoming',
            default => 'live',
        };
    }
}
