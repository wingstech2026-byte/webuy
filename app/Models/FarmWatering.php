<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmWatering extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['farm_id', 'actor_id', 'mission_key', 'water', 'day'];

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
