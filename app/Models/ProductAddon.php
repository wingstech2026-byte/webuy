<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An extra for a dish ("Extra meat +Le 15"): the buyer ticks any of them, each adds to the price of one dish. */
class ProductAddon extends Model
{
    protected $fillable = ['product_id', 'name', 'price', 'is_active', 'position'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
