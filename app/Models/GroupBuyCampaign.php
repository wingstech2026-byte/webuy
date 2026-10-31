<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A seller's group-buying offer: a lower price per unit when enough people pay within the time limit. */
class GroupBuyCampaign extends Model
{
    protected $fillable = [
        'product_id', 'seller_id', 'group_price', 'min_group_size', 'max_group_size', 'max_quantity_each', 'hours',
        'eligible_districts', 'collection_point_id', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'group_price' => 'decimal:2',
            'eligible_districts' => 'array',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
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

    public function collectionPoint(): BelongsTo
    {
        return $this->belongsTo(SellerLocation::class, 'collection_point_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(GroupBuy::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('is_active', true)->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isOpen(): bool
    {
        return $this->is_active && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function servesDistrict(?string $district): bool
    {
        return empty($this->eligible_districts) || in_array($district, $this->eligible_districts, true);
    }

    /** The real saving per unit against today's normal price (never invented). */
    public function savingPerUnit(): string
    {
        $saving = Money::sub($this->product->price, $this->group_price);

        return Money::cmp($saving, 0) > 0 ? $saving : '0.00';
    }
}
