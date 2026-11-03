<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\SellerReview;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    public function __construct(private TrialService $trials, private FarmService $farms, private ImageService $images) {}

    /**
     * Rate the seller (once per order) and, optionally, the products in the order (once per item),
     * with up to 4 photos of each product as it arrived.
     *
     * @param  array<int, array{rating: int|string|null, comment?: ?string, photos?: list<UploadedFile>}>  $itemReviews  order item id => review
     *
     * @throws MarketplaceException
     */
    public function submit(Order $order, User $buyer, ?int $sellerRating, ?string $sellerComment, array $itemReviews = []): void
    {
        if ($order->buyer_id !== $buyer->id) {
            throw new MarketplaceException('This is not your order.');
        }

        if ($order->status !== OrderStatus::Completed) {
            throw new MarketplaceException('You can leave a review once the order is completed.');
        }

        DB::transaction(function () use ($order, $buyer, $sellerRating, $sellerComment, $itemReviews) {
            $order->load('items');
            $touchedProducts = [];

            if ($sellerRating && ! SellerReview::where('order_id', $order->id)->exists()) {
                SellerReview::create([
                    'order_id' => $order->id,
                    'seller_id' => $order->seller_id,
                    'buyer_id' => $buyer->id,
                    'rating' => $sellerRating,
                    'comment' => $sellerComment ?: null,
                ]);
            }

            foreach ($order->items as $item) {
                $input = $itemReviews[$item->id] ?? null;

                if (! $input || empty($input['rating']) || Review::where('order_item_id', $item->id)->exists()) {
                    continue;
                }

                $review = Review::create([
                    'order_item_id' => $item->id,
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'seller_id' => $order->seller_id,
                    'buyer_id' => $buyer->id,
                    'rating' => (int) $input['rating'],
                    'comment' => ($input['comment'] ?? null) ?: null,
                    // Food: how it tasted, how big the portion was, how it was packed.
                    'taste' => isset($input['taste']) && $input['taste'] !== '' ? (int) $input['taste'] : null,
                    'portion' => isset($input['portion']) && $input['portion'] !== '' ? (int) $input['portion'] : null,
                    'packaging' => isset($input['packaging']) && $input['packaging'] !== '' ? (int) $input['packaging'] : null,
                ]);

                foreach (array_slice(array_values($input['photos'] ?? []), 0, 4) as $position => $photo) {
                    if ($photo instanceof UploadedFile) {
                        $this->images->attach($review, $photo, 'review', $buyer->id, $position);
                    }
                }

                $this->trials->onReview($review);
                $touchedProducts[] = $item->product_id;
            }

            $this->recalculateSeller($order->seller_id);

            foreach (array_unique($touchedProducts) as $productId) {
                $this->recalculateProduct($productId);
            }
        });

        $this->farms->mission($buyer, 'review');
    }

    /**
     * The shop answers a review in public (once; it can change the answer). The buyer is told.
     *
     * @throws MarketplaceException
     */
    public function reply(Review|SellerReview $review, User $by, string $reply): void
    {
        if ($by->seller?->id !== $review->seller_id) {
            throw new MarketplaceException('This review is not about your shop.');
        }

        ProductQuestionService::refuseContactDetails($reply);
        $review->forceFill(['seller_reply' => trim($reply), 'replied_at' => now()])->save();
        $review->loadMissing('buyer');
        $shop = Seller::find($review->seller_id);
        $link = $review instanceof Review ? route('products.show', $review->product_id ? Product::find($review->product_id) : null).'#reviews' : route('shops.show', $shop);
        app(NotificationService::class)->notify($review->buyer, "💬 {$shop->shop_name} answered your review", mb_substr(trim($reply), 0, 160), $link);
    }

    /** Average taste, portion and packaging of a dish (null when no food scores yet). */
    public static function foodScores(int $productId): ?array
    {
        $row = Review::where('product_id', $productId)->whereNotNull('taste')
            ->selectRaw('count(*) as n, avg(taste) as taste, avg(portion) as portion, avg(packaging) as packaging')->first();

        return $row && $row->n > 0 ? [
            'count' => (int) $row->n,
            'taste' => round((float) $row->taste, 1),
            'portion' => $row->portion !== null ? round((float) $row->portion, 1) : null,
            'packaging' => $row->packaging !== null ? round((float) $row->packaging, 1) : null,
        ] : null;
    }

    /** Average rating and number of ratings, kept on the seller and product for fast lists and sorting. */
    public function recalculateSeller(int $sellerId): void
    {
        $row = SellerReview::where('seller_id', $sellerId)->selectRaw('count(*) as n, coalesce(avg(rating), 0) as avg')->first();

        Seller::whereKey($sellerId)->update(['rating_count' => (int) $row->n, 'rating_avg' => round((float) $row->avg, 2)]);
    }

    public function recalculateProduct(int $productId): void
    {
        $row = Review::where('product_id', $productId)->selectRaw('count(*) as n, coalesce(avg(rating), 0) as avg')->first();

        Product::whereKey($productId)->update(['rating_count' => (int) $row->n, 'rating_avg' => round((float) $row->avg, 2)]);
    }

    /** Can this buyer still review something on this order? */
    public function canReview(Order $order, User $buyer): bool
    {
        if ($order->buyer_id !== $buyer->id || $order->status !== OrderStatus::Completed) {
            return false;
        }

        $order->loadMissing('items.review', 'sellerReview');

        return $order->sellerReview === null || $order->items->contains(fn ($item) => $item->review === null);
    }
}
