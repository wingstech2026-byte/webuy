<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** WiBuy Global: a product sourced abroad, with landed prices (goods, shipping and duties) by sea or air. */
class ImportProduct extends Model
{
    protected $fillable = ['title', 'description', 'origin', 'unit', 'min_quantity', 'sea_price', 'sea_days', 'air_price', 'air_days', 'deposit_percent', 'is_active'];

    protected function casts(): array
    {
        return [
            'sea_price' => 'decimal:2',
            'air_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->where('collection', 'gallery')->orderBy('position');
    }

    /** @return array<string, array{price: string, days: int}> */
    public function shippingOptions(): array
    {
        return array_filter([
            'sea' => $this->sea_price !== null ? ['price' => (string) $this->sea_price, 'days' => (int) $this->sea_days] : null,
            'air' => $this->air_price !== null ? ['price' => (string) $this->air_price, 'days' => (int) $this->air_days] : null,
        ]);
    }
}
