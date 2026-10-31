<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceCutHelper extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['price_cut_id', 'price_cut_campaign_id', 'helper_id', 'device_hash', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function helper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'helper_id');
    }
}
