<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** One slice of the daily spin wheel. The chance of winning it is its weight divided by all active weights. */
class SpinPrize extends Model
{
    protected $fillable = ['label', 'type', 'points', 'coupon_amount', 'coupon_min_spend', 'coupon_days', 'weight', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'coupon_amount' => 'decimal:2',
            'coupon_min_spend' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->where('weight', '>', 0)->orderBy('sort_order')->orderBy('id');
    }
}
