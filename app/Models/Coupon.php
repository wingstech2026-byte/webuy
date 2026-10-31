<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A discount for one person, paid for by WiBuy. Never cash: it can only lower the price of an order. */
class Coupon extends Model
{
    public const LOCKED = 'locked';

    public const AVAILABLE = 'available';

    public const RESERVED = 'reserved';

    public const USED = 'used';

    public const EXPIRED = 'expired';

    protected $fillable = [
        'user_id', 'title', 'source', 'amount', 'percent', 'max_discount', 'min_spend', 'product_id',
        'app_only', 'status', 'unlock_hint', 'checkout_id', 'discount_given', 'expires_at', 'used_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'percent' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_spend' => 'decimal:2',
            'discount_given' => 'decimal:2',
            'app_only' => 'boolean',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Coupons the person can use right now. */
    public function scopeUsable(Builder $query): void
    {
        $query->where('status', self::AVAILABLE)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isUsable(): bool
    {
        return $this->status === self::AVAILABLE && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** "Le 20 off orders over Le 100", "10% off (up to Le 50)". */
    public function getDescriptionAttribute(): string
    {
        $value = $this->percent !== null
            ? rtrim(rtrim((string) $this->percent, '0'), '.').'% off'.($this->max_discount ? ' (up to '.Money::format($this->max_discount).')' : '')
            : Money::format($this->amount).' off';

        if ($this->product_id) {
            return $value.' one '.($this->product?->title ?? 'product');
        }

        return Money::gt($this->min_spend, 0) ? $value.' orders over '.Money::format($this->min_spend) : $value.' any order';
    }
}
