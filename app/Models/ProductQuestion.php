<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's question about a product, and the shop's answer (shown on the product page once answered). */
class ProductQuestion extends Model
{
    protected $fillable = ['product_id', 'user_id', 'question'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'is_hidden' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Answered and not hidden: what everyone sees. */
    public function scopePublic(Builder $query): Builder
    {
        return $query->whereNotNull('answered_at')->where('is_hidden', false);
    }
}
