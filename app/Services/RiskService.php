<?php

namespace App\Services;

use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Support\RewardSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Warnings that protect buyers: prices far below similar products, new sellers with no completed orders,
 * and sellers asking to be paid outside WiBuy in chat.
 */
class RiskService
{
    /** Words people use to move payment off WiBuy. */
    private const OFF_PLATFORM = '/\b(pay|send|transfer)\b.{0,40}\b(direct(ly)?|outside|my (orange|afri)\s?money|my number|my account|cash)\b|\bpay (me )?(before|first)\b|\boutside (of )?wibuy\b|\bavoid (the )?(fee|commission)\b/i';

    /**
     * @return array<int, array{type: string, message: string}>
     */
    public function productWarnings(Product $product): array
    {
        $warnings = [];

        if ($median = $this->categoryMedian($product)) {
            $threshold = RewardSettings::int('risk_price_percent');

            if ((float) $product->price < $median * $threshold / 100) {
                $warnings[] = ['type' => 'low_price', 'message' => 'This price is much lower than similar products. Check the details and reviews, and always pay through WiBuy.'];
            }
        }

        $completed = Cache::remember("seller-completed:{$product->seller_id}", 600, fn () => Order::where('seller_id', $product->seller_id)
            ->where('status', OrderStatus::Completed)->count());

        if ($completed === 0) {
            $warnings[] = ['type' => 'new_seller', 'message' => 'New seller: no completed orders on WiBuy yet. Your money stays safe with WiBuy until you confirm delivery.'];
        }

        return $warnings;
    }

    private function categoryMedian(Product $product): ?float
    {
        if (! $product->category_id) {
            return null;
        }

        return Cache::remember("category-median:{$product->category_id}", 600, function () use ($product) {
            $prices = Product::listed()->where('category_id', $product->category_id)->pluck('price')->map(fn ($p) => (float) $p)->sort()->values();

            if ($prices->count() < 4) {
                return null;
            }

            $middle = intdiv($prices->count(), 2);

            return $prices->count() % 2 ? $prices[$middle] : ($prices[$middle - 1] + $prices[$middle]) / 2;
        });
    }

    public function asksToPayOutside(?string $text): bool
    {
        return $text !== null && preg_match(self::OFF_PLATFORM, $text) === 1;
    }

    /** After a chat message: if someone asks to be paid outside WiBuy, add a safety warning to the chat. */
    public function checkMessage(Message $message, Conversation $conversation): void
    {
        if (! $this->asksToPayOutside($message->body)) {
            return;
        }

        Message::create([
            'conversation_id' => $conversation->id,
            'user_id' => null,
            'type' => MessageType::System,
            'body' => '⚠️ Safety: never pay outside WiBuy. Payments made outside the app are not protected and cannot be refunded. Report anyone who asks.',
        ]);
    }
}
