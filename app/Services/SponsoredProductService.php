<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Exceptions\MarketplaceException;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sponsored products: a seller pays from their WiBuy balance to show a product first in search and in the
 * "Sponsored" row on the home page, for some days. Always labelled "Sponsored" so buyers know.
 */
class SponsoredProductService
{
    public const DAYS = [1, 3, 7, 14, 30];

    public function __construct(private LedgerService $ledger) {}

    public static function pricePerDay(): string
    {
        return RewardSettings::money('sponsored_price_per_day');
    }

    /** @throws MarketplaceException */
    public function promote(Product $product, User $sellerUser, int $days): Product
    {
        if (! in_array($days, self::DAYS, true)) {
            throw new MarketplaceException('Choose 1, 3, 7, 14 or 30 days.');
        }

        return DB::transaction(function () use ($product, $sellerUser, $days) {
            // Lock the shop so two quick taps cannot both spend the same balance.
            $seller = Seller::lockForUpdate()->findOrFail($product->seller_id);
            $product = Product::lockForUpdate()->findOrFail($product->id);

            if ($seller->user_id !== $sellerUser->id) {
                throw new MarketplaceException('This is not your product.');
            }

            if (! $product->isListed()) {
                throw new MarketplaceException('Only a live product can be sponsored. Put it live first.');
            }

            $cost = Money::mul(self::pricePerDay(), $days);
            $available = $this->ledger->available($seller);

            if (Money::gt($cost, $available)) {
                throw new MarketplaceException('Sponsoring for '.$days.' day(s) costs '.money($cost).'. You have '.money($available).' available.');
            }

            // Extends a running promotion instead of starting over.
            $from = $product->sponsored_until?->isFuture() ? $product->sponsored_until : now();
            $product->forceFill(['sponsored_until' => $from->copy()->addDays($days)])->save();

            LedgerEntry::create([
                'seller_id' => $seller->id,
                'type' => LedgerType::Sponsored,
                'amount' => Money::sub('0.00', $cost),
                'description' => "Sponsored: {$product->title}, {$days} day(s) (until {$product->sponsored_until->format('j M, g:ia')})",
            ]);

            return $product;
        });
    }

    /** @return Collection<int, Product> */
    public function forHome(int $limit = 8): Collection
    {
        return Product::listed()->where('sponsored_until', '>', now())->with(['seller', 'images', 'tiers'])->inRandomOrder()->limit($limit)->get();
    }
}
