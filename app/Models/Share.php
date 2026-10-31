<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A link someone shared (usually on WhatsApp). It counts as "successful" once another person opens it. */
class Share extends Model
{
    protected $fillable = ['user_id', 'token', 'kind', 'target', 'opened_at', 'opened_by_id', 'opened_device_hash', 'rewarded_at'];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'rewarded_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUrlAttribute(): string
    {
        return route('share.open', $this);
    }
}
