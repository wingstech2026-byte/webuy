<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Remind me to order this again every N days": a reminder only, the buyer still chooses and pays each time. */
class OrderRepeat extends Model
{
    protected $fillable = ['user_id', 'order_id', 'every_days', 'next_at', 'is_active'];

    protected function casts(): array
    {
        return ['next_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
