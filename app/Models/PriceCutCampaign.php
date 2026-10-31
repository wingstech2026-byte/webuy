<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "Help me cut the price" on one product: friends cut a random amount each until the floor price. */
class PriceCutCampaign extends Model
{
    protected $fillable = ['product_id', 'created_by_id', 'floor_price', 'hours', 'min_cut', 'max_cut', 'is_active', 'ends_at'];

    protected function casts(): array
    {
        return [
            'floor_price' => 'decimal:2',
            'min_cut' => 'decimal:2',
            'max_cut' => 'decimal:2',
            'is_active' => 'boolean',
            'ends_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function cuts(): HasMany
    {
        return $this->hasMany(PriceCut::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('is_active', true)->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isOpen(): bool
    {
        return $this->is_active && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
