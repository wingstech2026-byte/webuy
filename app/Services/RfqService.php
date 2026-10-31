<?php

namespace App\Services;

use App\Enums\Operator;
use App\Exceptions\MarketplaceException;
use App\Models\Checkout;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\RfqQuote;
use App\Models\Seller;
use App\Models\User;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Requests for quotation. Buyers post what they need; sellers answer with a quote that locks the price for
 * a set number of days. The buyer compares quotes side by side and accepts one, which becomes an order
 * at the quoted price.
 */
class RfqService
{
    public function __construct(private CheckoutService $checkouts) {}

    public function create(User $buyer, array $data, string $source = 'buyer'): Rfq
    {
        return Rfq::create($data + ['buyer_id' => $buyer->id, 'source' => $source, 'status' => 'open']);
    }

    /** @throws MarketplaceException */
    public function quote(Seller $seller, Rfq $rfq, Product $product, string $unitPrice, int $quantity, int $leadTimeDays, ?string $note): RfqQuote
    {
        if ($rfq->status !== 'open') {
            throw new MarketplaceException('This request is no longer open.');
        }

        if ($rfq->buyer_id === $seller->user_id) {
            throw new MarketplaceException('You cannot quote on your own request.');
        }

        if ($product->seller_id !== $seller->id || ! $product->isListed()) {
            throw new MarketplaceException('Choose one of your listed products.');
        }

        try {
            return RfqQuote::create([
                'rfq_id' => $rfq->id,
                'seller_id' => $seller->id,
                'product_id' => $product->id,
                'unit_price' => Money::from($unitPrice),
                'quantity' => $quantity,
                'lead_time_days' => $leadTimeDays,
                'note' => $note,
                'valid_until' => now()->addDays(RewardSettings::int('quote_valid_days')),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new MarketplaceException('You already sent a quote for this request.');
        }
    }

    /**
     * Quotes side by side: price, total with delivery to the buyer's district, time, rating and reliability.
     *
     * @return array<int, array<string, mixed>>
     */
    public function compare(Rfq $rfq): array
    {
        return $rfq->quotes()->with(['seller.deliveryAreas', 'product'])->get()
            ->map(function (RfqQuote $quote) use ($rfq) {
                $fee = $quote->seller->deliveryFeeFor($rfq->district);

                return [
                    'quote' => $quote,
                    'items_total' => $quote->items_total,
                    'delivery_fee' => $fee === null ? null : Money::from($fee),
                    'total' => Money::add($quote->items_total, $fee ?? '0'),
                    'can_deliver' => $fee !== null,
                    'days' => $quote->lead_time_days,
                    'is_valid' => $quote->isValid(),
                ];
            })
            ->sortBy(fn ($row) => [$row['is_valid'] ? 0 : 1, (float) $row['total']])
            ->values()->all();
    }

    /**
     * Accept a quote: an order at the quoted price is placed and the mobile money request sent.
     *
     * @throws MarketplaceException
     */
    public function accept(User $buyer, RfqQuote $quote, array $details, string $method, Operator $operator, string $payerPhone, string $plan = 'full'): Checkout
    {
        $rfq = $quote->rfq;

        if ($rfq->buyer_id !== $buyer->id) {
            throw new MarketplaceException('This is not your request.');
        }

        if ($rfq->status !== 'open' || ! $quote->isValid()) {
            throw new MarketplaceException('This quote has expired or the request is closed. Ask the seller for a new quote.');
        }

        return DB::transaction(function () use ($buyer, $quote, $rfq, $details, $method, $operator, $payerPhone, $plan) {
            $checkout = $this->checkouts->place(
                $buyer,
                [['product_id' => $quote->product_id, 'quantity' => $quote->quantity, 'unit_price' => (string) $quote->unit_price]],
                $details + ['district' => $rfq->district],
                [$quote->seller_id => $method],
                $operator,
                $payerPhone,
                null,
                'app',
                ['payment_plan' => $plan, 'rfq_quote_id' => $quote->id],
            );

            $quote->forceFill(['status' => 'accepted'])->save();
            $rfq->quotes()->whereKeyNot($quote->id)->update(['status' => 'declined']);
            $rfq->forceFill(['status' => 'awarded'])->save();

            return $checkout;
        });
    }
}
