<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WishlistItem extends Model
{
    protected $fillable = ['user_id', 'product_id', 'alert_price', 'alert_in_stock'];

    protected function casts(): array
    {
        return ['alert_price' => 'decimal:2', 'alert_in_stock' => 'boolean'];
    }

    /** Remember the price and stock when it is saved: later drops and restocks are told once (WishlistAlertService). */
    protected static function booted(): void
    {
        static::creating(function (WishlistItem $item) {
            $product = Product::find($item->product_id);
            $item->alert_price ??= $product?->price;
            $item->alert_in_stock = ($product?->stock ?? 0) > 0;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
