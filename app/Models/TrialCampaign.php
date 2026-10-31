<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A seller gives a few products free (or almost free) in return for honest reviews with photos. */
class TrialCampaign extends Model
{
    protected $fillable = ['product_id', 'seller_id', 'trial_price', 'slots', 'apply_until', 'review_days', 'status'];

    protected function casts(): array
    {
        return [
            'trial_price' => 'decimal:2',
            'apply_until' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(TrialApplication::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open')->where('apply_until', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->status === 'open' && $this->apply_until->isFuture();
    }

    public function selectedCount(): int
    {
        return $this->applications()->whereIn('status', ['selected', 'reviewed', 'overdue'])->count();
    }
}
