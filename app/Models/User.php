<?php

namespace App\Models;

use App\Enums\AdminRole;
use App\Enums\SellerStatus;
use App\Support\PhoneNumber;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Roles: every user is a buyer. A user with an approved Seller shop is also a seller.
 * Admins have is_admin = true and an admin_role (see AdminRole / AdminAccess). The role is
 * never mass assignable: it is only set by an owner on the Staff page.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    protected $fillable = ['name', 'phone', 'is_admin', 'last_login_at', 'chat_banned_at', 'referral_code', 'referred_by_id', 'signup_channel'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'admin_role' => AdminRole::class,
            'is_agent' => 'boolean',
            'is_rider' => 'boolean',
            'last_login_at' => 'datetime',
            'chat_banned_at' => 'datetime',
            'rider_available_until' => 'datetime',
        ];
    }

    /** A rider who said "I'm available" in the last few hours (for instant food jobs near them). */
    public function isRiderAvailable(): bool
    {
        return $this->is_rider && $this->rider_available_until?->isFuture() === true;
    }

    /** Name and location given (the phone is checked at login): needed before buying. */
    public function hasBuyerDetails(): bool
    {
        return filled($this->name) && filled($this->district) && filled($this->area);
    }

    public function seller(): HasOne
    {
        return $this->hasOne(Seller::class);
    }

    /** Shops this person follows (their news shows in "Following"). */
    public function followedShops(): BelongsToMany
    {
        return $this->belongsToMany(Seller::class, 'shop_follows')->withTimestamps();
    }

    /** The shop this person helps in (added by its owner). */
    public function staffShops(): BelongsToMany
    {
        return $this->belongsToMany(Seller::class, 'seller_staff')->withTimestamps();
    }

    /**
     * "$user->seller": the person's own shop, or else the shop they help in, so helpers use the same seller pages.
     * Money and shop settings check isShopOwner() as well.
     */
    public function getSellerAttribute(): ?Seller
    {
        return $this->getRelationValue('seller') ?? $this->getRelationValue('staffShops')->first();
    }

    /** Their own shop only (never one they help in). */
    public function ownShop(): ?Seller
    {
        return $this->getRelationValue('seller');
    }

    public function isShopOwner(): bool
    {
        return $this->ownShop()?->status === SellerStatus::Approved;
    }

    public function isShopStaff(): bool
    {
        return $this->ownShop() === null && $this->getRelationValue('staffShops')->isNotEmpty();
    }

    /** Deliveries this user took as a WiBuy rider. */
    public function deliveries(): HasMany
    {
        return $this->hasMany(DeliveryJob::class, 'rider_id');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    /** A buyer whose business WiBuy verified: sees business-only wholesale prices, RFQs and big-order tools. */
    public function isVerifiedBusiness(): bool
    {
        return $this->businessProfile?->isVerified() === true;
    }

    public function savedAddresses(): HasMany
    {
        return $this->hasMany(SavedAddress::class)->orderByDesc('is_default')->orderBy('label');
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function farm(): HasOne
    {
        return $this->hasOne(Farm::class);
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_id');
    }

    /** The WiBuy Pass month that is running now, if any. */
    public function activeMembership(): ?Membership
    {
        return $this->memberships()->where('status', 'active')->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())->latest('ends_at')->first();
    }

    public function hasPass(): bool
    {
        return $this->activeMembership() !== null;
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /** True when this person has a shop that WiBuy approved (and has not suspended). */
    public function isSeller(): bool
    {
        return $this->seller?->status === SellerStatus::Approved;
    }

    public function isChatBanned(): bool
    {
        return $this->chat_banned_at !== null;
    }

    public function getDisplayPhoneAttribute(): string
    {
        return PhoneNumber::display($this->phone);
    }

    public function getFirstNameAttribute(): string
    {
        return Str::of($this->name ?? '')->before(' ')->toString() ?: 'friend';
    }

    public function getRoleLabelAttribute(): string
    {
        return $this->is_admin ? 'Admin' : ($this->isSeller() ? 'Seller' : 'Buyer');
    }
}
