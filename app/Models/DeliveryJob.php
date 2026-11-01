<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A delivery done by a WiBuy rider. See DeliveryService for the steps. */
class DeliveryJob extends Model
{
    public const OPEN = 'open';

    public const ASSIGNED = 'assigned';

    public const PICKED_UP = 'picked_up';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    /** Shop -> buyer's door (the seller asked for a rider). */
    public const KIND_DOOR = 'door';

    /** WiBuy station -> another WiBuy station (the buyer collects there). */
    public const KIND_TRANSFER = 'transfer';

    /** WiBuy station -> buyer's door. */
    public const KIND_STATION_DOOR = 'station_door';

    protected $fillable = [
        'order_id', 'seller_id', 'rider_id', 'status', 'kind', 'pickup_station_id', 'dropoff_station_id', 'pickup_name', 'pickup_address', 'pickup_latitude', 'pickup_longitude',
        'dropoff_district', 'dropoff_latitude', 'dropoff_longitude', 'fee', 'proof', 'proof_note',
        'assigned_at', 'picked_up_at', 'delivered_at',
    ];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'pickup_latitude' => 'decimal:6',
            'pickup_longitude' => 'decimal:6',
            'dropoff_latitude' => 'decimal:6',
            'dropoff_longitude' => 'decimal:6',
            'assigned_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'rider_latitude' => 'decimal:6',
            'rider_longitude' => 'decimal:6',
            'rider_located_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function pickupStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'pickup_station_id');
    }

    public function dropoffStation(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'dropoff_station_id');
    }

    /** Station to station: the receiving station confirms it, not the buyer's code. */
    public function isTransfer(): bool
    {
        return $this->kind === self::KIND_TRANSFER;
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function photos(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->orderBy('position');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::OPEN, self::ASSIGNED, self::PICKED_UP], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OPEN => 'Waiting for a rider',
            self::ASSIGNED => 'Rider on the way to the shop',
            self::PICKED_UP => 'Rider on the way to the buyer',
            self::DELIVERED => 'Delivered',
            default => 'Cancelled',
        };
    }
}
