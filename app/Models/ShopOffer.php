<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A shop's own offer, paid by the shop (it lowers the order total and the shop's earnings):
 *   spend: "Spend Le 500, save Le 50" (automatic)
 *   code:  a code the shop shares ("FRIDAY10"), once per buyer
 *   bogo:  "Buy 2, get 1 free" on one product
 */
class ShopOffer extends Model
{
    public const SPEND = 'spend';

    public const CODE = 'code';

    public const BOGO = 'bogo';

    protected $fillable = [
        'seller_id', 'kind', 'title', 'code', 'amount', 'percent', 'max_discount', 'min_spend', 'product_id',
        'buy_quantity', 'max_uses', 'starts_at', 'ends_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'percent' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'min_spend' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Switched on, within its dates and not used up. */
    public function scopeRunning(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->where(fn (Builder $q) => $q->whereNull('max_uses')->orWhereColumn('uses', '<', 'max_uses'));
    }

    /** What it takes off a spend of $subtotal (spend and code offers). */
    public function valueFor(string $subtotal): string
    {
        $value = $this->percent !== null ? Money::percentOf($subtotal, (string) $this->percent) : Money::from($this->amount ?? 0);

        if ($this->max_discount !== null && Money::gt($value, $this->max_discount)) {
            $value = Money::from($this->max_discount);
        }

        return Money::gt($value, $subtotal) ? Money::from($subtotal) : $value;
    }

    /** "Le 50 off orders over Le 500", "10% off with code FRIDAY10", "Buy 2 Jollof rice, get 1 free". */
    public function getLabelAttribute(): string
    {
        if ($this->kind === self::BOGO) {
            return "Buy {$this->buy_quantity} ".($this->product?->title ?? '').', get 1 free';
        }

        $value = $this->percent !== null
            ? rtrim(rtrim((string) $this->percent, '0'), '.').'% off'.($this->max_discount ? ' (up to '.Money::format($this->max_discount).')' : '')
            : Money::format($this->amount).' off';

        return $value.(Money::gt($this->min_spend, 0) ? ' orders over '.Money::format($this->min_spend) : '').($this->kind === self::CODE ? " with code {$this->code}" : '');
    }
}
