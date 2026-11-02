<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\User;

/**
 * Questions and answers on product pages. A buyer asks; the shop (owner or staff) is told and answers; the
 * answer then shows for everyone and the person who asked is told. Phone numbers and links to pay outside
 * WiBuy are refused, like in chat.
 */
class ProductQuestionService
{
    public const MAX_OPEN_PER_PERSON = 5;

    public function __construct(private NotificationService $notifications) {}

    /** @throws MarketplaceException */
    public function ask(Product $product, User $user, string $question): ProductQuestion
    {
        if (! $product->isListed()) {
            throw new MarketplaceException('This product is not on sale.');
        }

        if ($product->seller->user_id === $user->id) {
            throw new MarketplaceException('This is your own product.');
        }

        $this->refuseContactDetails($question);

        $open = ProductQuestion::where('user_id', $user->id)->where('product_id', $product->id)->whereNull('answered_at')->count();

        if ($open >= self::MAX_OPEN_PER_PERSON) {
            throw new MarketplaceException('Please wait for the shop to answer your other questions first.');
        }

        $question = ProductQuestion::create(['product_id' => $product->id, 'user_id' => $user->id, 'question' => trim($question)]);
        $this->notifications->notify($product->seller->user, '❓ New question', "About {$product->title}: \"".mb_substr($question->question, 0, 120).'"', route('seller.questions.index'));

        return $question;
    }

    /** @throws MarketplaceException */
    public function answer(ProductQuestion $question, User $by, string $answer): ProductQuestion
    {
        $this->refuseContactDetails($answer);

        $question->forceFill(['answer' => trim($answer), 'answered_by' => $by->id, 'answered_at' => now()])->save();
        $question->loadMissing('product', 'asker');
        $this->notifications->notify($question->asker, '💬 The shop answered', "{$question->product->title}: ".mb_substr($question->answer, 0, 160), route('products.show', $question->product));

        return $question;
    }

    /**
     * Also used for live comments.
     *
     * @throws MarketplaceException
     */
    public static function refuseContactDetails(string $text): void
    {
        // Phone numbers (7+ digits) and "pay me directly" would take buyers outside WiBuy's protection.
        if (preg_match('/(\d[\s\-]?){7,}/', $text) || preg_match('/\b(whats?app|orange money|afrimoney|pay (me )?direct)/i', $text)) {
            throw new MarketplaceException('Please do not share phone numbers or ways to pay outside WiBuy. Keep it to questions about the product.');
        }
    }
}
