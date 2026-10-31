<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Returns, while WiBuy still holds the money (after delivery, before the order completes):
 *  1. the buyer asks, choosing items, a reason and photos; the order pauses ("Return in progress");
 *  2. the seller accepts (and says how to send it back) or refuses within 48 hours;
 *     a refusal or no answer goes to the WiBuy team as a problem report (existing dispute flow);
 *  3. the seller confirms the items came back: the stock returns and the buyer is refunded
 *     (everything for a full return; the returned items' share otherwise, and the order completes
 *     for the rest). If the seller never confirms, it goes to the WiBuy team too.
 * Every step locks the return and the order, so it cannot happen twice.
 */
class ReturnService
{
    public function __construct(
        private PaymentService $payments,
        private OrderService $orders,
        private DisputeService $disputes,
        private ImageService $images,
        private SmsService $sms,
    ) {}

    /**
     * @param  array<int, int>  $quantities  order item id => how many to return
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws MarketplaceException
     */
    public function request(Order $order, User $buyer, array $quantities, string $reason, string $description, array $photos = []): ReturnRequest
    {
        return DB::transaction(function () use ($order, $buyer, $quantities, $reason, $description, $photos) {
            $order = Order::lockForUpdate()->with('items', 'seller.user')->findOrFail($order->id);

            if ($order->buyer_id !== $buyer->id) {
                throw new MarketplaceException('This is not your order.');
            }

            if ($order->status !== OrderStatus::Delivered) {
                throw new MarketplaceException('You can ask for a return after the order is delivered and before it is completed.');
            }

            if ($order->returnRequest()->exists()) {
                throw new MarketplaceException('There is already a return for this order.');
            }

            if (! array_key_exists($reason, ReturnRequest::REASONS)) {
                throw new MarketplaceException('Choose why you are returning it.');
            }

            $items = [];
            $value = '0.00';
            $allBack = true;

            foreach ($order->items as $item) {
                $quantity = (int) ($quantities[$item->id] ?? 0);

                if ($quantity < 0 || $quantity > $item->quantity) {
                    throw new MarketplaceException("You can return at most {$item->quantity} of {$item->title}.");
                }

                $allBack = $allBack && $quantity === $item->quantity;

                if ($quantity > 0) {
                    $items[] = ['order_item_id' => $item->id, 'quantity' => $quantity];
                    $value = Money::add($value, Money::mul($item->unit_price, $quantity));
                }
            }

            if ($items === []) {
                throw new MarketplaceException('Choose at least one item to return.');
            }

            $return = ReturnRequest::create([
                'order_id' => $order->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $order->seller_id,
                'reason' => $reason,
                'description' => $description,
                'items' => $items,
                'is_full' => $allBack,
                'returned_value' => $allBack ? $order->total : $value,
                'refund_amount' => $allBack ? $order->amount_paid : $this->buyerShare($order, $value),
                'status' => ReturnRequest::REQUESTED,
                'respond_by' => now()->addHours(config('wibuy.returns.respond_hours')),
            ]);

            $order->forceFill(['status' => OrderStatus::Returning])->save();

            foreach (array_slice($photos, 0, 4) as $i => $photo) {
                $this->images->attach($return, $photo, 'return', $buyer->id, $i);
            }

            $this->sms->send($order->seller->user->phone, "WiBuy: A buyer wants to return items from order {$order->number}. Please answer within ".config('wibuy.returns.respond_hours').' hours in your seller dashboard.');

            return $return;
        });
    }

    /** The seller agrees and tells the buyer how to send it back (address, pickup, rider...). */
    public function accept(ReturnRequest $return, User $sellerUser, string $instructions): ReturnRequest
    {
        return $this->step($return, $sellerUser, [ReturnRequest::REQUESTED], function (ReturnRequest $return, Order $order) use ($instructions) {
            $return->forceFill([
                'status' => ReturnRequest::ACCEPTED,
                'return_instructions' => $instructions,
                'accepted_at' => now(),
                'send_back_by' => now()->addDays(config('wibuy.returns.send_back_days')),
            ])->save();

            $this->sms->send($order->buyer->phone, "WiBuy: The seller accepted your return for order {$order->number}. How to send it back: {$instructions}");
        });
    }

    /** The seller refuses: the WiBuy team decides, with the buyer's reason and photos. */
    public function refuse(ReturnRequest $return, User $sellerUser, string $note): ReturnRequest
    {
        return $this->step($return, $sellerUser, [ReturnRequest::REQUESTED], function (ReturnRequest $return) use ($note) {
            $return->forceFill(['seller_note' => $note])->save();
            $this->escalate($return, "The seller refused the return: {$note}");
        });
    }

    /** The items are back with the seller: stock returns and the buyer is refunded. */
    public function received(ReturnRequest $return, User $sellerUser): ReturnRequest
    {
        return $this->step($return, $sellerUser, [ReturnRequest::ACCEPTED], function (ReturnRequest $return, Order $order) {
            $return->forceFill(['status' => ReturnRequest::RECEIVED, 'received_at' => now()])->save();

            $quantities = collect($return->items)->mapWithKeys(fn ($i) => [$i['order_item_id'] => $i['quantity']]);
            $order->items->whereIn('id', $quantities->keys())->groupBy('product_id')
                ->each(fn ($items, $productId) => Product::whereKey($productId)->lockForUpdate()->first()
                    ?->increment('stock', $items->sum(fn ($item) => $quantities[$item->id])));

            // Everything came back: the whole payment is refunded and the seller gets nothing; the order
            // shows "Refunded" once the gateway confirms (PaymentService::applyRefund).
            if ($return->is_full) {
                $this->payments->refundOrder($order, 'Items returned to the seller.');

                return;
            }

            // Part of the order came back: refund that share, and complete the order for what the buyer kept.
            $order->forceFill(['status' => OrderStatus::Delivered, 'returned_value' => $return->returned_value])->save();
            $this->payments->refundPart($order, $return, 'Returned items.');
            $this->orders->complete($order);
            $this->sms->send($order->buyer->phone, 'WiBuy: The seller got your returned items. We are sending '.Money::format($return->refund_amount)." back to your mobile money for order {$order->number}.");
        });
    }

    /** The seller did not answer in time, or never confirmed the items came back. @return int how many */
    public function escalateOverdue(): int
    {
        $count = 0;

        ReturnRequest::where(fn ($q) => $q->where('status', ReturnRequest::REQUESTED)->where('respond_by', '<=', now()))
            ->orWhere(fn ($q) => $q->where('status', ReturnRequest::ACCEPTED)->where('send_back_by', '<=', now()))
            ->pluck('id')->each(function (int $id) use (&$count) {
                DB::transaction(function () use ($id, &$count) {
                    $return = ReturnRequest::lockForUpdate()->find($id);

                    if ($return->status === ReturnRequest::REQUESTED && $return->respond_by->isPast()) {
                        $this->escalate($return, 'The seller did not answer the return in time.');
                        $count++;
                    } elseif ($return->status === ReturnRequest::ACCEPTED && $return->send_back_by->isPast()) {
                        $this->escalate($return, 'The return was not confirmed as received in time.');
                        $count++;
                    }
                });
            });

        return $count;
    }

    /** Hand the return to the WiBuy team as a problem report (admins refund the buyer or pay the seller). */
    private function escalate(ReturnRequest $return, string $why): void
    {
        $order = Order::lockForUpdate()->findOrFail($return->order_id);
        $order->forceFill(['status' => OrderStatus::Delivered])->save();

        $dispute = $this->disputes->open($order, $return->buyer, 'Return: '.ReturnRequest::REASONS[$return->reason], "{$why}\n\nReturned items: {$return->itemsLine()}\n{$return->description}");
        $return->forceFill(['status' => ReturnRequest::ESCALATED, 'dispute_id' => $dispute->id])->save();

        $this->sms->send($return->buyer->phone, "WiBuy: Your return for order {$order->number} is now with the WiBuy team. We will check it and decide. Your money stays safe until then.");
    }

    /** The buyer's money for the returned goods, in proportion to what they paid (coupons were WiBuy's). */
    private function buyerShare(Order $order, string $value): string
    {
        if (Money::isZero($order->total)) {
            return '0.00';
        }

        $share = bcdiv(bcmul($value, $order->amount_paid, 6), (string) $order->total, 2);

        return Money::cmp($share, $order->amount_paid) > 0 ? $order->amount_paid : $share;
    }

    /**
     * @param  list<string>  $from
     * @param  callable(ReturnRequest, Order): void  $change
     *
     * @throws MarketplaceException
     */
    private function step(ReturnRequest $return, User $sellerUser, array $from, callable $change): ReturnRequest
    {
        return DB::transaction(function () use ($return, $sellerUser, $from, $change) {
            $locked = ReturnRequest::lockForUpdate()->findOrFail($return->id);
            $order = Order::lockForUpdate()->with('items', 'buyer', 'seller')->findOrFail($locked->order_id);

            if ($order->seller->user_id !== $sellerUser->id) {
                throw new MarketplaceException('This is not your order.');
            }

            if (! in_array($locked->status, $from, true)) {
                throw new MarketplaceException("This return is \"{$locked->statusLabel()}\", so that cannot be done now.");
            }

            $change($locked, $order);

            return $locked->refresh();
        });
    }
}
