<?php

namespace App\Models;

use App\Enums\Operator;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'checkout_id', 'order_id', 'payout_id', 'membership_id', 'payable_type', 'payable_id', 'refund_of_id',
        'kind', 'provider', 'provider_reference', 'phone', 'operator', 'amount', 'status', 'raw_response',
    ];

    protected function casts(): array
    {
        return [
            'kind' => PaymentKind::class,
            'operator' => Operator::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'raw_response' => 'array',
        ];
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function scopeOfKind(Builder $query, PaymentKind $kind): void
    {
        $query->where('kind', $kind);
    }

    /** Money coming in from a buyer's phone (an order or a WiBuy Pass month). */
    public function isDeposit(): bool
    {
        return in_array($this->kind, [PaymentKind::Deposit, PaymentKind::Membership, PaymentKind::Charge], true);
    }

    public function isRefund(): bool
    {
        return $this->kind === PaymentKind::Refund;
    }

    public function isPayout(): bool
    {
        return $this->kind === PaymentKind::Payout;
    }

    /** What a charge pays for (an order milestone, an inspection or an import order). */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }
}
