<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceTier extends Model
{
    protected $fillable = ['product_id', 'min_quantity', 'unit_price', 'business_only'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:2', 'business_only' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
