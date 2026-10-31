<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** What a fully grown plant gives: a large coupon, or a real gift delivered with the next order. */
class FarmReward extends Model
{
    protected $fillable = ['plant', 'label', 'type', 'coupon_amount', 'coupon_min_spend', 'gift_description', 'cost', 'is_active'];

    protected function casts(): array
    {
        return [
            'coupon_amount' => 'decimal:2',
            'coupon_min_spend' => 'decimal:2',
            'cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public static function for(string $plant): ?self
    {
        return static::where('plant', $plant)->where('is_active', true)->latest('id')->first();
    }
}
