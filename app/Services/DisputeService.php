<?php

namespace App\Services;

use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Requests\DisputeRequest;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Sms\SmsMessages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * A buyer reports a problem: the order is frozen (no auto-completion, no seller credit) until an admin
 * decides to refund the buyer or release the money to the seller.
 */
class DisputeService
{
    public function __construct(
        private OrderService $orders,
        private PaymentService $payments,
        private ImageService $images,
        private SmsService $sms,
    ) {}

    /**
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws MarketplaceException
     */
    public function open(Order $order, User $buyer, string $reason, string $description, array $photos = []): Dispute
    {
        return DB::transaction(function () use ($order, $buyer, $reason, $description, $photos) {
            $order = Order::lockForUpdate()->findOrFail($order->id);

            if ($order->buyer_id !== $buyer->id) {
                throw new MarketplaceException('This is not your order.');
            }

            if (! in_array($order->status, OrderStatus::disputable(), true)) {
                throw new MarketplaceException('You can only report a problem on an order the seller has accepted that is not finished yet.');
            }

            $dispute = Dispute::create([
                'order_id' => $order->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $order->seller_id,
                'reason' => $reason,
                'description' => $description,
                'status' => DisputeStatus::Open,
            ]);

            $order->forceFill(['status' => OrderStatus::Disputed, 'status_before_dispute' => $order->status->value])->save();

            foreach (array_slice($photos, 0, 4) as $i => $photo) {
                $this->images->attach($dispute, $photo, 'dispute', $buyer->id, $i);
            }

            $dispute->load('order');
            $this->sms->send($order->seller->user->phone, SmsMessages::disputeOpened($dispute));

            return $dispute;
        });
    }

    /**
     * The seller tells their side.
     *
     * @param  array<int, UploadedFile>  $photos
     *
     * @throws MarketplaceException
     */
    public function respond(Dispute $dispute, User $sellerUser, string $response, array $photos = []): Dispute
    {
        return DB::transaction(function () use ($dispute, $sellerUser, $response, $photos) {
            $dispute = Dispute::lockForUpdate()->findOrFail($dispute->id);

            if ($dispute->seller->user_id !== $sellerUser->id) {
                throw new MarketplaceException('This is not your order.');
            }

            if (! $dispute->isOpen()) {
                throw new MarketplaceException('This problem has already been decided.');
            }

            $dispute->forceFill(['seller_response' => $response, 'seller_responded_at' => now()])->save();

            foreach (array_slice($photos, 0, 4) as $i => $photo) {
                $this->images->attach($dispute, $photo, 'dispute', $sellerUser->id, 10 + $i);
            }

            return $dispute;
        });
    }

    /**
     * An admin decides. "buyer": the buyer is refunded in full. "seller": the order completes and the
     * seller is credited (total minus commission).
     *
     * @throws MarketplaceException
     */
    public function resolve(Dispute $dispute, User $admin, string $decision, ?string $note = null): Dispute
    {
        if (! in_array($decision, ['buyer', 'seller'], true)) {
            throw new MarketplaceException('Choose to refund the buyer or pay the seller.');
        }

        return DB::transaction(function () use ($dispute, $admin, $decision, $note) {
            $dispute = Dispute::lockForUpdate()->findOrFail($dispute->id);

            if (! $dispute->isOpen()) {
                throw new MarketplaceException('This problem has already been decided.');
            }

            $order = Order::lockForUpdate()->findOrFail($dispute->order_id);

            $dispute->forceFill([
                'status' => $decision === 'buyer' ? DisputeStatus::ResolvedBuyer : DisputeStatus::ResolvedSeller,
                'resolution_note' => $note,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ])->save();

            if ($decision === 'buyer') {
                // The order becomes "refunded" when pawaPay confirms the refund.
                $this->payments->refundOrder($order, 'A problem you reported was decided in your favour.');

                // Fake goods sold with a genuine guarantee: a coupon on top of the refund, paid by the seller.
                // The reason is saved as its wording (see DisputeRequest::REASONS).
                if (in_array($dispute->reason, ['fake', DisputeRequest::REASONS['fake']], true)) {
                    app(BuyerProtectionService::class)->compensateFakeGoods($order);
                }
            } else {
                $this->orders->complete($order, afterDispute: true);
            }

            $dispute->load('order', 'buyer', 'seller.user');
            $this->sms->send($dispute->buyer->phone, SmsMessages::disputeResolved($dispute, $decision === 'buyer'));
            $this->sms->send($dispute->seller->user->phone, SmsMessages::disputeResolved($dispute, $decision === 'buyer'));

            return $dispute;
        });
    }
}
