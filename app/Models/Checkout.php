<?php

namespace App\Models;

use App\Enums\CheckoutStatus;
use App\Enums\Operator;
use App\Enums\PaymentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One mobile money payment that covers one order per seller. */
class Checkout extends Model
{
    protected $fillable = ['buyer_id', 'total', 'discount', 'coupon_id', 'channel', 'status', 'payer_phone', 'operator', 'expires_at', 'paid_at'];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'discount' => 'decimal:2',
            'status' => CheckoutStatus::class,
            'operator' => Operator::class,
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestDeposit(): HasOne
    {
        return $this->hasOne(Payment::class)->where('kind', PaymentKind::Deposit)->latestOfMany();
    }
}
