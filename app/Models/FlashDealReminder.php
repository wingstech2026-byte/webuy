<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlashDealReminder extends Model
{
    protected $fillable = ['flash_deal_id', 'user_id', 'notified_at'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(FlashDeal::class, 'flash_deal_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
