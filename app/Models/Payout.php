<?php

namespace App\Models;

use App\Enums\Operator;
use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PayoutStatus::class,
            'operator' => Operator::class,
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** Set instead of the seller when a WiBuy rider withdraws delivery fees. */
    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    /** Who gets the money: the shop owner or the rider. */
    public function recipient(): User
    {
        return $this->rider ?? $this->seller->user;
    }

    public function recipientName(): string
    {
        return $this->rider ? "{$this->rider->name} (rider)" : $this->seller->shop_name;
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
