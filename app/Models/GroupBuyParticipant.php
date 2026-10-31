<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member of a group. Only "paid" members count towards the group size. */
class GroupBuyParticipant extends Model
{
    protected $fillable = ['group_buy_id', 'user_id', 'checkout_id', 'order_id', 'quantity', 'status', 'device_hash', 'paid_at'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(GroupBuy::class, 'group_buy_id');
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
