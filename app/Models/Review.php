<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A buyer's rating of one purchased product. */
class Review extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_free_trial' => 'boolean'];
    }

    /** Photos with the review (free trial reviews must have some). */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->where('collection', 'review')->orderBy('position');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
