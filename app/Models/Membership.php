<?php

namespace App\Models;

use App\Enums\Operator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One month of WiBuy Pass, paid once by mobile money. It is never renewed or charged automatically. */
class Membership extends Model
{
    protected $fillable = ['user_id', 'price', 'status', 'payer_phone', 'operator', 'starts_at', 'ends_at', 'reminded_at'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'operator' => Operator::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }
}
