<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\LiveSession;
use App\Models\Product;
use App\Models\Seller;
use App\Models\ShopOffer;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Following shops: a buyer follows shops they like and sees their news in one feed: new products, offers and live
 * shows. Followers are told when a shop adds products (at most once a day per shop), starts an offer or goes live.
 */
class FollowService
{
    public const MAX_TOLD = 1000;

    public function __construct(private NotificationService $notifications) {}

    /**
     * Follow, or stop following. @return bool whether the person now follows the shop
     *
     * @throws MarketplaceException
     */
    public function toggle(User $user, Seller $seller): bool
    {
        if ($seller->user_id === $user->id) {
            throw new MarketplaceException('This is your own shop.');
        }

        if ($user->followedShops()->whereKey($seller->id)->exists()) {
            $user->followedShops()->detach($seller->id);

            return false;
        }

        $user->followedShops()->attach($seller->id);

        return true;
    }

    public function follows(?User $user, Seller $seller): bool
    {
        return $user !== null && $user->followedShops()->whereKey($seller->id)->exists();
    }

    /** @return array{products: Collection, offers: Collection, live: Collection, shops: Collection} */
    public function feed(User $user): array
    {
        $shopIds = $user->followedShops()->pluck('sellers.id');

        return [
            'shops' => $user->followedShops()->with('logo')->orderBy('shop_name')->get(),
            'products' => Product::listed()->whereIn('seller_id', $shopIds)->where('products.created_at', '>=', now()->subDays(30))
                ->with('images', 'seller', 'tiers')->latest('products.id')->limit(30)->get(),
            'offers' => ShopOffer::whereIn('seller_id', $shopIds)->running()->where('kind', '!=', ShopOffer::CODE)->with('seller', 'product')->latest('id')->limit(20)->get(),
            'live' => LiveSession::whereIn('seller_id', $shopIds)->whereIn('status', [LiveSession::LIVE, LiveSession::SCHEDULED])->with('seller')->latest('id')->limit(10)->get(),
        ];
    }

    /** Tell the shop's followers (one notification each). */
    public function tellFollowers(Seller $seller, string $title, string $body, string $link): void
    {
        $seller->followers()->limit(self::MAX_TOLD)->get()
            ->each(fn (User $user) => $this->notifications->notify($user, $title, $body, $link));
    }

    /** A new product is on sale: followers hear about it, at most once a day per shop. */
    public function productPublished(Product $product): void
    {
        if (! Cache::add("follow-news:{$product->seller_id}:".now()->toDateString(), 1, now()->endOfDay())) {
            return;
        }

        $product->loadMissing('seller');
        $this->tellFollowers($product->seller, "🆕 New from {$product->seller->shop_name}", $product->title, route('products.show', $product));
    }
}
