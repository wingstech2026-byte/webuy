<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckIn extends Model
{
    protected $fillable = ['user_id', 'day', 'streak', 'points'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
