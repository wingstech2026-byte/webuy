<?php

namespace App\Models;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\SellerStatus;
use App\Support\Money;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'seller_id', 'category_id', 'title', 'slug', 'description', 'price', 'compare_at_price',
        'stock', 'unit', 'min_order_quantity', 'condition', 'status', 'is_deal', 'is_featured',
        'rating_avg', 'rating_count', 'sold_count', 'member_price', 'lead_time_days', 'sample_price',
        'genuine_guarantee',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'genuine_guarantee' => 'boolean',
            'sponsored_until' => 'datetime',
            'rating_avg' => 'decimal:2',
            'condition' => ProductCondition::class,
            'status' => ProductStatus::class,
            'is_deal' => 'boolean',
            'is_featured' => 'boolean',
            'member_price' => 'decimal:2',
            'sample_price' => 'decimal:2',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** The seller paid to show it first (always labelled "Sponsored"). */
    public function isSponsored(): bool
    {
        return $this->sponsored_until !== null && $this->sponsored_until->isFuture();
    }

    /** The seller's short video of the product, if any. */
    public function getVideoUrlAttribute(): ?string
    {
        return $this->video_path ? Storage::disk(Image::DISK)->url($this->video_path) : null;
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->where('collection', 'gallery')->orderBy('position')->orderBy('id');
    }

    public function flashDeals(): HasMany
    {
        return $this->hasMany(FlashDeal::class);
    }

    /** The lightning deal running right now, if any. */
    public function liveFlashDeal(): ?FlashDeal
    {
        $deals = $this->relationLoaded('flashDeals') ? $this->flashDeals : $this->flashDeals()->where('ends_at', '>', now())->get();

        return $deals->first(fn (FlashDeal $deal) => $deal->isLive());
    }

    public function priceCutCampaigns(): HasMany
    {
        return $this->hasMany(PriceCutCampaign::class);
    }

    /**
     * The price for this buyer: tiers, then a live lightning deal (up to its per-order limit), then the
     * WiBuy Pass member price, whichever is lowest.
     */
    public function unitPriceForBuyer(int $quantity, bool $isMember = false, bool $isBusiness = false, ?ProductVariant $variant = null): string
    {
        // A variant with its own price is sold at exactly that price (bulk and member prices are the product's).
        if ($variant?->price !== null) {
            return Money::from($variant->price);
        }

        $price = $this->unitPriceFor($quantity, $isBusiness);
        $deal = $this->liveFlashDeal();

        if ($deal && $quantity <= $deal->max_per_order && Money::cmp($deal->deal_price, $price) < 0) {
            $price = (string) $deal->deal_price;
        }

        if ($isMember && $this->member_price !== null && Money::cmp($this->member_price, $price) < 0) {
            $price = (string) $this->member_price;
        }

        return Money::from($price);
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(PriceTier::class)->orderBy('min_quantity');
    }

    /** Options such as size or colour; when there are active ones, the buyer must pick one. */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position')->orderBy('id');
    }

    public function hasVariants(): bool
    {
        return $this->relationLoaded('variants')
            ? $this->variants->contains('is_active', true)
            : $this->variants()->active()->exists();
    }

    /** Restocked above the low-stock line: the shop may be told again next time. */
    protected static function booted(): void
    {
        static::saving(function (Product $product) {
            if ($product->isDirty('stock') && $product->stock > 5) {
                $product->low_stock_notified_at = null;
            }
        });
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ProductQuestion::class);
    }

    /** Reviews with photos first (they help other buyers most), then the newest. */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)
            // A count (never null) sorts the same way on SQLite, MySQL and PostgreSQL.
            ->orderByDesc(Image::selectRaw('count(*)')->whereColumn('imageable_id', 'reviews.id')
                ->where('imageable_type', (new Review)->getMorphClass())->where('collection', 'review'))
            ->latest('id');
    }

    /** Products buyers can see and buy: live, from an approved shop. */
    public function scopeListed(Builder $query): void
    {
        $query->where('products.status', ProductStatus::Active)
            ->whereHas('seller', fn (Builder $q) => $q->where('status', SellerStatus::Approved));
    }

    public function isListed(): bool
    {
        return $this->status === ProductStatus::Active && $this->seller?->status === SellerStatus::Approved;
    }

    /**
     * The price per unit for a quantity: the best matching wholesale tier, otherwise the retail price.
     * Tiers: from 10 units 90.00, from 50 units 80.00 => 9 units 100.00, 10-49 units 90.00, 50+ units 80.00.
     */
    /** The tier price for this quantity. Business-only tiers count only for verified business buyers. */
    public function unitPriceFor(int $quantity, bool $isBusiness = false): string
    {
        $tiers = $this->relationLoaded('tiers') ? $this->tiers : $this->tiers()->get();

        $tier = $tiers->where('min_quantity', '<=', $quantity)
            ->filter(fn ($tier) => $isBusiness || ! $tier->business_only)
            ->sortByDesc('min_quantity')->first();

        return $tier ? (string) $tier->unit_price : (string) $this->price;
    }

    public function getHasTiersAttribute(): bool
    {
        return ($this->relationLoaded('tiers') ? $this->tiers : $this->tiers()->get())->isNotEmpty();
    }

    /** The lowest price this product is sold at (retail or the cheapest tier). */
    public function getLowestPriceAttribute(): string
    {
        $tiers = $this->relationLoaded('tiers') ? $this->tiers : $this->tiers()->get();

        return (string) $tiers->pluck('unit_price')->push($this->price)->sort()->first();
    }

    public function getDiscountPercentAttribute(): int
    {
        return $this->compare_at_price ? Money::percentOff($this->compare_at_price, $this->price) : 0;
    }

    /** Picture for lists: the first image that finished processing. */
    public function getCoverAttribute(): ?Image
    {
        $images = $this->relationLoaded('images') ? $this->images : $this->images()->get();

        return $images->firstWhere('status', 'ready');
    }

    public function getCoverThumbUrlAttribute(): ?string
    {
        return $this->cover?->thumb_url;
    }

    public function getUrlAttribute(): string
    {
        return route('products.show', $this);
    }
}
