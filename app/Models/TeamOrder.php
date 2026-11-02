<?php

namespace App\Models;

use Database\Factories\TeamOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An office team order: one person opens it for a food shop and the office address, colleagues add their own
 * meals and each pays their own share. It all comes in one delivery; the organiser pays the delivery fee once.
 */
class TeamOrder extends Model
{
    /** @use HasFactory<TeamOrderFactory> */
    use HasFactory;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $fillable = [
        'token', 'leader_id', 'seller_id', 'title', 'deliver_at', 'closes_at', 'recipient_name', 'recipient_phone',
        'district', 'address', 'landmark', 'building', 'floor', 'latitude', 'longitude', 'status',
    ];

    protected function casts(): array
    {
        return [
            'deliver_at' => 'datetime',
            'closes_at' => 'datetime',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN && $this->closes_at->isFuture();
    }

    /** "Africell House, floor 3, 12 Siaka Stevens Street". */
    public function getPlaceAttribute(): string
    {
        return collect([$this->building, $this->floor ? "floor {$this->floor}" : null, $this->address])->filter()->implode(', ');
    }

    /** Where every colleague's order goes (the checkout details). */
    public function checkoutDetails(): array
    {
        return [
            'name' => $this->recipient_name,
            'phone' => $this->recipient_phone,
            'district' => $this->district,
            'address' => $this->place,
            'landmark' => $this->landmark,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'deliver_at' => $this->deliver_at?->toIso8601String(),
            'team_order_id' => $this->id,
        ];
    }
}
