<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A viewer's comment during a live show (no phone numbers or "pay me directly", like questions). */
class LiveComment extends Model
{
    protected $fillable = ['live_session_id', 'user_id', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
