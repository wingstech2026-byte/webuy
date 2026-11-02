<?php

namespace App\Models;

use App\Enums\Operator;
use App\Enums\SellerStatus;
use App\Services\SettingsService;
use Carbon\CarbonInterface;
use Database\Factories\SellerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'rating_avg', 'rating_count', 'approved_at', 'free_delivery_from', 'holiday_until', 'holiday_message',
        'is_instant', 'opens_at', 'closes_at', 'prep_minutes',
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
            'free_delivery_from' => 'decimal:2',
            'holiday_until' => 'date',
            'is_instant' => 'boolean',
            'rating_avg' => 'decimal:2',
            'approved_at' => 'datetime',
            'owner_id_number' => 'encrypted',
            'owner_id_submitted_at' => 'datetime',
        ];
    }

    /** The owner's ID card never leaves the server in JSON (API, logs); admins see it on the approval page only. */
    protected $hidden = ['owner_id_number', 'owner_id_number_hash', 'owner_id_front_path', 'owner_id_back_path'];

    /** Opening hours ("08:00"-"20:00", also past midnight); no hours set: always open. */
    public function isOpenAt(?CarbonInterface $at = null): bool
    {
        if ($this->opens_at === null || $this->closes_at === null) {
            return true;
        }

        $time = ($at ?? now())->format('H:i');

        return $this->opens_at <= $this->closes_at
            ? $time >= $this->opens_at && $time < $this->closes_at
            : $time >= $this->opens_at || $time < $this->closes_at;
    }

    public function hoursLabel(): ?string
    {
        return $this->opens_at && $this->closes_at ? "{$this->opens_at}–{$this->closes_at}" : null;
    }

    /** Instant delivery estimate: cooking, then about 4 minutes per km in town traffic. Never a promise. */
    public function instantEtaMinutes(float $km): int
    {
        return (int) ($this->prep_minutes + 5 + ceil($km * 4));
    }

    /** Closed for a few days: the products show, but cannot be ordered until the shop is back. */
    public function isOnHoliday(): bool
    {
        return $this->holiday_until !== null && $this->holiday_until->endOfDay()->isFuture();
    }

    public function holidayNotice(): string
    {
        return "{$this->shop_name} is closed until ".$this->holiday_until->format('j M').($this->holiday_message ? ": {$this->holiday_message}" : '.');
    }

    /** The owner sent their national ID number and a photo of the card (needed before approval). */
    public function hasOwnerIdentity(): bool
    {
        return filled($this->owner_id_number) && filled($this->owner_id_front_path);
    }

    /** A one-way fingerprint of an ID number, to spot the same card on two shops without storing it in clear. */
    public static function idFingerprint(string $number): string
    {
        return hash_hmac('sha256', $number, (string) config('app.key'));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** People who follow this shop. */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'shop_follows')->withTimestamps();
    }

    public function offers(): HasMany
    {
        return $this->hasMany(ShopOffer::class);
    }

    public function bundles(): HasMany
    {
        return $this->hasMany(Bundle::class);
    }

    /** Helpers who manage orders and products (not money or shop settings). */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'seller_staff')->withTimestamps();
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
