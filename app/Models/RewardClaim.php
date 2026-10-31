<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A real prize someone won (WiBuy Farm harvest). Gifts ride along with the winner's next paid order. */
class RewardClaim extends Model
{
    protected $fillable = ['user_id', 'farm_reward_id', 'type', 'description', 'cost', 'coupon_id', 'order_id', 'status', 'fulfilled_at'];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
