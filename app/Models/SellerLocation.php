<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A seller's shop, warehouse or pickup point, with its delivery radius and fees. A pin set by the
 * seller is "unverified" until the WiBuy team has checked the premises; a pin never proves identity.
 */
class SellerLocation extends Model
{
    public const TYPES = ['shop' => 'Shop', 'warehouse' => 'Warehouse', 'pickup_point' => 'Pickup point'];

    protected $fillable = [
        'seller_id', 'type', 'public_name', 'public_address', 'district', 'town', 'latitude', 'longitude', 'is_residence',
        'delivery_enabled', 'pickup_enabled', 'pickup_instructions', 'delivery_radius_km', 'base_fee', 'per_km_fee',
        'min_order_value', 'delivery_days', 'delivery_window', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'is_residence' => 'boolean',
            'delivery_enabled' => 'boolean',
            'pickup_enabled' => 'boolean',
            'delivery_radius_km' => 'decimal:2',
            'base_fee' => 'decimal:2',
            'per_km_fee' => 'decimal:2',
            'min_order_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Where buyers may see this place. A home business is shown only roughly (2 decimals, about 1 km),
     * so the house cannot be found from the app.
     *
     * @return array{latitude: float, longitude: float, approximate: bool}
     */
    public function publicPoint(): array
    {
        $round = $this->is_residence ? 2 : 6;

        return [
            'latitude' => round((float) $this->latitude, $round),
            'longitude' => round((float) $this->longitude, $round),
            'approximate' => $this->is_residence,
        ];
    }
}
