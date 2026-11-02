<?php

namespace App\Models;

use App\Enums\DeliveryMethod;
use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Services\RiderTrackingService;
use App\Support\PhoneNumber;
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

    /** The buyer's station collection code is never sent anywhere. */
    protected $hidden = ['collect_code_hash'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'delivery_method' => DeliveryMethod::class,
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'shop_paid_delivery' => 'decimal:2',
            'commission' => 'decimal:2',
            'late_compensation' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'total' => 'decimal:2',
            'discount' => 'decimal:2',
            'returned_value' => 'decimal:2',
            'delivery_latitude' => 'decimal:6',
            'delivery_longitude' => 'decimal:6',
            'distance_km' => 'decimal:2',
            'stock_reserved' => 'boolean',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'is_instant' => 'boolean',
            'deliver_at' => 'datetime',
            'food_ready_at' => 'datetime',
            'cook_reminded_at' => 'datetime',
            'accept_by' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'at_drop_station_at' => 'datetime',
            'at_collect_station_at' => 'datetime',
            'station_fees_credited_at' => 'datetime',
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

    /** Ordered for someone else (family, a friend): they get the delivery or collection code too. */
    public function recipientIsSomeoneElse(): bool
    {
        $recipient = PhoneNumber::normalize($this->recipient_phone);

        return $recipient !== null && $recipient !== PhoneNumber::normalize($this->buyer?->phone);
    }

    /** An office team order this belongs to. */
    public function teamOrder(): BelongsTo
    {
        return $this->belongsTo(TeamOrder::class);
    }

    /** "Remind me to order this again every N days". */
    public function repeat(): HasOne
    {
        return $this->hasOne(OrderRepeat::class);
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

    /** The seller takes it to a WiBuy station (the buyer collects there, or a rider brings it from there). */
    public function viaStation(): bool
    {
        return (bool) $this->delivery_method?->viaStation();
    }

    /** The station nearest to the shop, where the seller drops the parcel. */
    public function dropStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'drop_station_id');
    }

    /** The station where the buyer collects (none for delivery to the door). */
    public function collectStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'collect_station_id');
    }

    /** What the seller earns once the order completes: everything the buyer paid minus WiBuy's commission. */
    /** What the buyer really paid: the total minus WiBuy-funded discounts (coupons, WiBuy Pass free delivery). */
    /** The group-buy place this order belongs to, if any. */
    public function groupParticipant(): HasOne
    {
        return $this->hasOne(GroupBuyParticipant::class);
    }

    public function returnRequest(): HasOne
    {
        return $this->hasOne(ReturnRequest::class);
    }

    public function deliveryJob(): HasOne
    {
        return $this->hasOne(DeliveryJob::class);
    }

    /**
     * What the buyer sees of a WiBuy rider delivery: the stage and who is bringing it. No location: the stages
     * come from the rider's own steps. The rider's phone shows only while the delivery is under way.
     *
     * @return ?array{stage: string, label: string, rider_name: ?string, rider_phone: ?string, steps: list<array{label: string, at: ?string}>}
     */
    public function riderDelivery(): ?array
    {
        $job = $this->deliveryJob;

        if (! $job || $job->status === DeliveryJob::CANCELLED) {
            return null;
        }

        $active = in_array($job->status, [DeliveryJob::ASSIGNED, DeliveryJob::PICKED_UP], true);

        return [
            'stage' => $job->status,
            'label' => match ($job->status) {
                DeliveryJob::OPEN => 'Looking for a WiBuy rider',
                DeliveryJob::ASSIGNED => 'The rider is going to the shop to collect your order',
                DeliveryJob::PICKED_UP => 'The rider has your order and is coming to you',
                default => 'Delivered by the WiBuy rider',
            },
            'rider_name' => $job->rider?->name,
            'rider_phone' => $active ? $job->rider?->phone : null,
            // Live: where the rider is, how far and how long (only while the parcel is with the rider).
            'tracking' => app(RiderTrackingService::class)->tracking($job),
            // After a door delivery the buyer can rate the rider once.
            'can_rate' => $job->status === DeliveryJob::DELIVERED && ! $job->isTransfer() && $job->rider_id !== null && $job->rating === null,
            'rating' => $job->rating,
            'steps' => [
                ['label' => 'Rider found', 'at' => $job->assigned_at?->toIso8601String()],
                ['label' => 'Rider collected your order', 'at' => $job->picked_up_at?->toIso8601String()],
                ['label' => 'Handed over to you', 'at' => $job->delivered_at?->toIso8601String()],
            ],
        ];
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
        return match (true) {
            $this->isPickup() => 'Ready for pickup',
            $this->viaStation() => 'At the WiBuy station',
            default => 'Shipped',
        };
    }

    public function statusLabel(): string
    {
        return $this->status === OrderStatus::Shipped ? $this->shipped_label : $this->status->label();
    }
}
