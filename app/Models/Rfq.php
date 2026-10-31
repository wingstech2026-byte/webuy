<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A request for quotation: a buyer says what they need; sellers answer with quotes. */
class Rfq extends Model
{
    protected $fillable = ['buyer_id', 'category_id', 'title', 'description', 'quantity', 'unit', 'district', 'target_price', 'needed_by', 'status', 'source'];

    protected function casts(): array
    {
        return [
            'target_price' => 'decimal:2',
            'needed_by' => 'date',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(RfqQuote::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', 'open');
    }
}
