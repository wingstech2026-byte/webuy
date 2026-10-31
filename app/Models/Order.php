<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'delivery_method' => DeliveryMethod::class,
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'commission' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'total' => 'decimal:2',
            'discount' => 'decimal:2',
            'delivery_latitude' => 'decimal:6',
            'delivery_longitude' => 'decimal:6',
            'distance_km' => 'decimal:2',
            'stock_reserved' => 'boolean',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'accept_by' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            $order->number ??= static::generateNumber();
        });
    }

    public static function generateNumber(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $number = 'WB-';
            for ($i = 0; $i < 7; $i++) {
                $number .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('number', $number)->exists());

        return $number;
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class)->latest('id');
    }

    public function openDispute(): HasOne
    {
        return $this->hasOne(Dispute::class)->where('status', DisputeStatus::Open)->latestOfMany();
    }

    public function sellerReview(): HasOne
    {
        return $this->hasOne(SellerReview::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function refundPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->where('kind', PaymentKind::Refund)->latestOfMany();
    }

    public function isPickup(): bool
    {
        return $this->delivery_method === DeliveryMethod::Pickup;
    }

    /** What the seller earns once the order completes: everything the buyer paid minus WiBuy's commission. */
    /** What the buyer really paid: the total minus WiBuy-funded discounts (coupons, WiBuy Pass free delivery). */
    /** The group-buy place this order belongs to, if any. */
    public function groupParticipant(): HasOne
    {
        return $this->hasOne(GroupBuyParticipant::class);
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(OrderMilestone::class)->orderBy('position');
    }

    public function inspection(): HasOne
    {
        return $this->hasOne(Inspection::class);
    }

    public function rfqQuote(): BelongsTo
    {
        return $this->belongsTo(RfqQuote::class);
    }

    /** Paid in parts and some parts are still open: the seller must not ship yet. */
    public function hasUnpaidMilestones(): bool
    {
        return $this->milestones()->where('status', 'pending')->exists();
    }

    public function getAmountPaidAttribute(): string
    {
        return bcsub((string) $this->total, (string) ($this->discount ?? '0'), 2);
    }

    public function getSellerEarningsAttribute(): string
    {
        return bcsub((string) $this->total, (string) $this->commission, 2);
    }

    public function getItemCountAttribute(): int
    {
        return (int) $this->items->sum('quantity');
    }

    /** Label for the "shipped" step: pickup orders are "ready for pickup". */
    public function getShippedLabelAttribute(): string
    {
        return $this->isPickup() ? 'Ready for pickup' : 'Shipped';
    }

    public function statusLabel(): string
    {
        return $this->status === OrderStatus::Shipped ? $this->shipped_label : $this->status->label();
    }
}
