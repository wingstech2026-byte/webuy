<?php

namespace App\Models;

use App\Enums\Operator;
use App\Enums\SellerStatus;
use App\Services\SettingsService;
use Database\Factories\SellerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Seller extends Model
{
    /** @use HasFactory<SellerFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'shop_name', 'slug', 'description', 'district', 'area', 'pickup_address',
        'payout_phone', 'payout_operator', 'business_registration_no', 'status', 'is_verified',
        'rejection_reason', 'offers_delivery', 'offers_pickup', 'commission_percent',
        'rating_avg', 'rating_count', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SellerStatus::class,
            'payout_operator' => Operator::class,
            'is_verified' => 'boolean',
            'offers_delivery' => 'boolean',
            'offers_pickup' => 'boolean',
            'commission_percent' => 'decimal:2',
            'rating_avg' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Shops, warehouses and pickup points on the map. */
    public function locations(): HasMany
    {
        return $this->hasMany(SellerLocation::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function deliveryAreas(): HasMany
    {
        return $this->hasMany(SellerDeliveryArea::class)->orderBy('district');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class)->latest('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(SellerReview::class)->latest('id');
    }

    public function logo(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable')->where('collection', 'logo')->latestOfMany();
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('status', SellerStatus::Approved);
    }

    public function isApproved(): bool
    {
        return $this->status === SellerStatus::Approved;
    }

    /** The commission rate (percent) for new orders: this seller's own rate, else the site-wide one. */
    public function commissionRate(): string
    {
        return (string) ($this->commission_percent ?? SettingsService::commissionPercent());
    }

    /** What this seller charges to deliver to a district, or null when they do not deliver there. */
    public function deliveryFeeFor(?string $district): ?string
    {
        if (! $this->offers_delivery || ! $district) {
            return null;
        }

        $areas = $this->relationLoaded('deliveryAreas') ? $this->deliveryAreas : $this->deliveryAreas()->get();

        return $areas->firstWhere('district', $district)?->fee;
    }

    public function getLocationAttribute(): string
    {
        return trim(($this->area ? $this->area.', ' : '').$this->district);
    }
}
