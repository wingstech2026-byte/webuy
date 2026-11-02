<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\LiveComment;
use App\Models\LiveSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use App\Models\WishlistItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Live selling: a shop schedules a show with its YouTube or Facebook live link and the products it will show,
 * goes live (people who bought from it or saved its products are told), pins the product it is showing, and ends
 * it. Viewers comment and buy while watching; the show stays as a replay.
 */
class LiveSellingService
{
    public const MAX_TOLD = 500;

    public function __construct(private NotificationService $notifications) {}

    /**
     * @param  list<int>  $productIds
     *
     * @throws MarketplaceException
     */
    public function schedule(Seller $seller, string $title, string $streamUrl, ?CarbonInterface $startsAt, array $productIds): LiveSession
    {
        if (! LiveSession::allowedUrl($streamUrl)) {
            throw new MarketplaceException('Paste the link of your YouTube or Facebook live video.');
        }

        $products = Product::where('seller_id', $seller->id)->listed()->whereIn('id', $productIds)->pluck('id');

        if ($products->isEmpty()) {
            throw new MarketplaceException('Choose the products you will show.');
        }

        $live = LiveSession::create(['seller_id' => $seller->id, 'title' => $title, 'stream_url' => $streamUrl, 'starts_at' => $startsAt, 'status' => LiveSession::SCHEDULED]);
        $live->products()->attach($products);

        return $live;
    }

    /** @throws MarketplaceException */
    public function start(LiveSession $live): void
    {
        if ($live->status !== LiveSession::SCHEDULED) {
            throw new MarketplaceException('This show already started.');
        }

        $live->update(['status' => LiveSession::LIVE, 'started_at' => now()]);
        $live->loadMissing('seller');

        // Told once: people who bought from the shop, and people who saved one of its products.
        $buyers = Order::where('seller_id', $live->seller_id)->whereNotIn('status', [OrderStatus::PendingPayment])->distinct()->pluck('buyer_id');
        $savers = WishlistItem::whereIn('product_id', $live->seller->products()->select('id'))->distinct()->pluck('user_id');
        $followers = $live->seller->followers()->pluck('users.id');

        User::whereIn('id', $followers->merge($buyers)->merge($savers)->unique()->take(self::MAX_TOLD))->where('id', '!=', $live->seller->user_id)->get()
            ->each(fn (User $user) => $this->notifications->notify($user, "🔴 {$live->seller->shop_name} is live", $live->title, route('live.show', $live)));
    }

    /** @throws MarketplaceException */
    public function pin(LiveSession $live, Product $product): void
    {
        if (! $live->products()->whereKey($product->id)->exists()) {
            throw new MarketplaceException('Pin one of the products in this show.');
        }

        $live->update(['pinned_product_id' => $product->id]);
    }

    public function end(LiveSession $live): void
    {
        $live->update(['status' => LiveSession::ENDED, 'ended_at' => now()]);
    }

    /** @throws MarketplaceException */
    public function comment(LiveSession $live, User $user, string $body): LiveComment
    {
        if (! $live->isLive()) {
            throw new MarketplaceException('Comments are open while the show is live.');
        }

        ProductQuestionService::refuseContactDetails($body);

        return $live->comments()->create(['user_id' => $user->id, 'body' => trim($body)]);
    }

    /** What the watch page polls: new comments, the pinned product and the status. */
    public function feed(LiveSession $live, int $afterId = 0): array
    {
        $live->loadMissing('pinnedProduct');

        return [
            'status' => $live->status,
            'pinned' => $live->pinnedProduct && $live->pinnedProduct->isListed() ? [
                'id' => $live->pinnedProduct->id, 'title' => $live->pinnedProduct->title, 'slug' => $live->pinnedProduct->slug,
                'price' => (string) $live->pinnedProduct->price, 'url' => route('products.show', $live->pinnedProduct),
            ] : null,
            'comments' => $live->comments()->with('user:id,name')->where('id', '>', $afterId)->latest('id')->limit(50)->get()->reverse()->values()
                ->map(fn (LiveComment $c) => ['id' => $c->id, 'name' => Str::before((string) $c->user->name, ' '), 'body' => $c->body]),
        ];
    }
}
