<?php

namespace App\Services;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\DeliveryJob;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * WiBuy riders:
 *  1. the seller asks for a rider on an accepted delivery order (the job shows only areas and the fee);
 *  2. a rider takes it and then sees the buyer's address and phone;
 *  3. at the shop the rider marks it picked up: the order is "shipped" and the buyer gets a 4-digit code by SMS;
 *  4. at the door the rider types the buyer's code (5 tries), or takes a photo when the buyer cannot give it.
 * The order is then "delivered"; the buyer still confirms or reports a problem as usual.
 * Every step locks the job, so two riders cannot take the same one.
 */
class DeliveryService
{
    public const MAX_ACTIVE_PER_RIDER = 5;

    public const MAX_CODE_ATTEMPTS = 5;

    public function __construct(private OrderService $orders, private SmsService $sms, private ImageService $images) {}

    /** @throws MarketplaceException */
    public function request(Order $order, User $sellerUser): DeliveryJob
    {
        return DB::transaction(function () use ($order, $sellerUser) {
            $order = Order::lockForUpdate()->with('seller.user', 'seller.locations')->findOrFail($order->id);

            if ($order->seller->user_id !== $sellerUser->id) {
                throw new MarketplaceException('This is not your order.');
            }

            if ($order->status !== OrderStatus::Accepted || $order->delivery_method !== DeliveryMethod::Delivery) {
                throw new MarketplaceException('A rider can be requested for an accepted delivery order that has not left yet.');
            }

            if ($order->hasUnpaidMilestones()) {
                throw new MarketplaceException('The buyer has not paid the balance yet.');
            }

            $existing = DeliveryJob::where('order_id', $order->id)->first();

            if ($existing && $existing->isActive()) {
                throw new MarketplaceException('A rider is already requested for this order.');
            }

            $shop = $order->seller->locations->where('is_active', true)->where('is_residence', false)->first()
                ?? $order->seller->locations->where('is_active', true)->first();
            // A home business shows riders only its area (about 1 km), like it shows buyers.
            $pickupPoint = $shop?->publicPoint();

            $job = $existing ?? new DeliveryJob(['order_id' => $order->id]);
            $job->fill([
                'seller_id' => $order->seller_id,
                'rider_id' => null,
                'status' => DeliveryJob::OPEN,
                'pickup_name' => $order->seller->shop_name,
                'pickup_address' => $shop?->public_address ?? $order->seller->pickup_address ?? $order->seller->district,
                'pickup_latitude' => $pickupPoint['latitude'] ?? null,
                'pickup_longitude' => $pickupPoint['longitude'] ?? null,
                'dropoff_district' => $order->district,
                'dropoff_latitude' => $order->delivery_latitude,
                'dropoff_longitude' => $order->delivery_longitude,
                'fee' => $order->delivery_fee,
                'assigned_at' => null,
                'picked_up_at' => null,
            ])->save();

            return $job;
        });
    }

    /** A rider takes an open job. */
    public function accept(DeliveryJob $job, User $rider): DeliveryJob
    {
        if (! $rider->is_rider) {
            throw new MarketplaceException('Only WiBuy riders can take deliveries.');
        }

        return $this->step($job, null, [DeliveryJob::OPEN], function (DeliveryJob $job) use ($rider) {
            $active = DeliveryJob::where('rider_id', $rider->id)->whereIn('status', [DeliveryJob::ASSIGNED, DeliveryJob::PICKED_UP])->count();

            if ($active >= self::MAX_ACTIVE_PER_RIDER) {
                throw new MarketplaceException('Finish your other deliveries first (at most '.self::MAX_ACTIVE_PER_RIDER.' at a time).');
            }

            $job->forceFill(['status' => DeliveryJob::ASSIGNED, 'rider_id' => $rider->id, 'assigned_at' => now()])->save();
            $this->sms->send($job->order->seller->user->phone, "WiBuy: Rider {$rider->name} ({$rider->display_phone}) will collect order {$job->order->number}.");
        });
    }

    /** The rider gives the job back before collecting it. */
    public function release(DeliveryJob $job, User $rider): DeliveryJob
    {
        return $this->step($job, $rider, [DeliveryJob::ASSIGNED], function (DeliveryJob $job) {
            $job->forceFill(['status' => DeliveryJob::OPEN, 'rider_id' => null, 'assigned_at' => null])->save();
        });
    }

    /** The goods left the shop with the rider: the order is shipped and the buyer gets the handover code. */
    public function pickedUp(DeliveryJob $job, User $rider): DeliveryJob
    {
        return $this->step($job, $rider, [DeliveryJob::ASSIGNED], function (DeliveryJob $job) use ($rider) {
            $code = (string) random_int(1000, 9999);
            $job->forceFill(['status' => DeliveryJob::PICKED_UP, 'picked_up_at' => now(), 'code_hash' => Hash::make($code), 'code_attempts' => 0])->save();

            $this->orders->ship($job->order, "WiBuy rider {$rider->name}, {$rider->display_phone}");
            $this->sms->send($job->order->buyer->phone, "WiBuy: Your order {$job->order->number} is on its way with rider {$rider->name}. Give the rider this code only when you have your goods: {$code}");
        });
    }

    /** The buyer gave the code: delivered. */
    public function deliverWithCode(DeliveryJob $job, User $rider, string $code): DeliveryJob
    {
        $wrong = false;

        try {
            return $this->step($job, $rider, [DeliveryJob::PICKED_UP], function (DeliveryJob $job) use ($code, &$wrong) {
                if ($job->code_attempts >= self::MAX_CODE_ATTEMPTS) {
                    throw new MarketplaceException('Too many wrong codes. Take a photo of the handover instead.');
                }

                if (! Hash::check(trim($code), (string) $job->code_hash)) {
                    $wrong = true;

                    throw new MarketplaceException('That code is not right. Ask the buyer to check the SMS from WiBuy.');
                }

                $this->finish($job, 'code', null);
            });
        } catch (MarketplaceException $e) {
            // Counted outside the rolled-back step, so guessing stops after a few tries.
            if ($wrong) {
                DeliveryJob::whereKey($job->id)->increment('code_attempts');
            }

            throw $e;
        }
    }

    /**
     * The buyer could not give the code: a photo of the handover is the proof instead.
     *
     * @throws MarketplaceException
     */
    public function deliverWithPhoto(DeliveryJob $job, User $rider, UploadedFile $photo, ?string $note): DeliveryJob
    {
        return $this->step($job, $rider, [DeliveryJob::PICKED_UP], function (DeliveryJob $job) use ($rider, $photo, $note) {
            $this->images->attach($job, $photo, 'delivery', $rider->id);
            $this->finish($job, 'photo', $note);
        });
    }

    /** The seller no longer needs a rider (before collection). */
    public function cancel(DeliveryJob $job, User $sellerUser): DeliveryJob
    {
        if ($job->seller->user_id !== $sellerUser->id) {
            throw new MarketplaceException('This is not your order.');
        }

        return $this->step($job, null, [DeliveryJob::OPEN, DeliveryJob::ASSIGNED], function (DeliveryJob $job) {
            if ($job->rider) {
                $this->sms->send($job->rider->phone, "WiBuy: The shop cancelled the delivery of order {$job->order->number}. You do not need to collect it.");
            }

            $job->forceFill(['status' => DeliveryJob::CANCELLED])->save();
        });
    }

    private function finish(DeliveryJob $job, string $proof, ?string $note): void
    {
        $job->forceFill(['status' => DeliveryJob::DELIVERED, 'delivered_at' => now(), 'proof' => $proof, 'proof_note' => $note])->save();
        $this->orders->markDelivered($job->order);
    }

    /**
     * @param  list<string>  $from
     * @param  callable(DeliveryJob): void  $change
     *
     * @throws MarketplaceException
     */
    private function step(DeliveryJob $job, ?User $rider, array $from, callable $change): DeliveryJob
    {
        return DB::transaction(function () use ($job, $rider, $from, $change) {
            $locked = DeliveryJob::lockForUpdate()->with('order.buyer', 'order.seller.user', 'rider', 'seller')->findOrFail($job->id);

            if ($rider && $locked->rider_id !== $rider->id) {
                throw new MarketplaceException('This delivery is not yours.');
            }

            if (! in_array($locked->status, $from, true)) {
                throw new MarketplaceException("This delivery is \"{$locked->statusLabel()}\", so that cannot be done now.");
            }

            $change($locked);

            return $locked->refresh();
        });
    }
}
