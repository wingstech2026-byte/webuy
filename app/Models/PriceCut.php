<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One buyer's price-cutting attempt. When it reaches the floor, the buyer gets a coupon for the difference. */
class PriceCut extends Model
{
    public const ACTIVE = 'active';

    public const REACHED = 'reached';

    public const EXPIRED = 'expired';

    protected $fillable = ['price_cut_campaign_id', 'user_id', 'token', 'start_price', 'current_price', 'status', 'coupon_id', 'expires_at'];

    protected function casts(): array
    {
        return [
            'start_price' => 'decimal:2',
            'current_price' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PriceCutCampaign::class, 'price_cut_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function helpers(): HasMany
    {
        return $this->hasMany(PriceCutHelper::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->expires_at->isFuture();
    }

    public function getUrlAttribute(): string
    {
        return route('price-cuts.show', $this);
    }
}
