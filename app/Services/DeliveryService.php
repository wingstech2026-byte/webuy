<?php

namespace App\Services;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\DeliveryJob;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
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

            if ($sellerUser->seller?->id !== $order->seller_id) { // the owner or a shop helper
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
                // Free delivery from the shop: the buyer paid nothing, the shop pays the rider (from its earnings).
                'fee' => Money::isZero($order->delivery_fee) ? $order->shop_paid_delivery : $order->delivery_fee,
                'is_instant' => (bool) $order->is_instant,
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
            // Parcels on the same station-to-station route are one trip; joining a trip already taken is free.
            $sameTrip = $this->activeTrips($rider)->contains($this->tripKey($job));

            if (! $sameTrip && $this->activeTrips($rider)->count() >= self::MAX_ACTIVE_PER_RIDER) {
                throw new MarketplaceException('Finish your other deliveries first (at most '.self::MAX_ACTIVE_PER_RIDER.' at a time).');
            }

            $job->forceFill(['status' => DeliveryJob::ASSIGNED, 'rider_id' => $rider->id, 'assigned_at' => now()])->save();
            // Whoever hands the parcel over is told who is coming: the shop, or the partner station.
            $handsOver = $job->pickupStation?->partner?->phone ?? $job->order->seller->user->phone;
            $this->sms->send($handsOver, "WiBuy: Rider {$rider->name} ({$rider->display_phone}) will collect order {$job->order->number}.");
        });
    }

    /**
     * Station to station: take every open parcel on the same route (same two stations) in one trip.
     *
     * @return int how many parcels were taken
     *
     * @throws MarketplaceException
     */
    public function acceptRoute(DeliveryJob $job, User $rider): int
    {
        if (! $job->isTransfer() && $job->order?->team_order_id === null) {
            throw new MarketplaceException('Only station-to-station parcels and office orders can be taken together.');
        }

        $taken = 0;

        foreach ($this->sameRoute($job)->pluck('id') as $id) {
            try {
                $this->accept(DeliveryJob::findOrFail($id), $rider);
                $taken++;
            } catch (MarketplaceException $e) {
                if ($taken === 0) {
                    throw $e; // the first one tells the rider why
                }
            }
        }

        return $taken;
    }

    /** Open parcels going between the same two stations, or one office order's parcels (this one included). */
    public function sameRoute(DeliveryJob $job): Builder
    {
        if (! $job->isTransfer()) {
            $team = $job->order?->team_order_id;

            return DeliveryJob::where('status', DeliveryJob::OPEN)->where('seller_id', $job->seller_id)
                ->when($team, fn (Builder $q) => $q->whereHas('order', fn (Builder $o) => $o->where('team_order_id', $team)), fn (Builder $q) => $q->whereKey($job->id));
        }

        return DeliveryJob::where('status', DeliveryJob::OPEN)->where('kind', DeliveryJob::KIND_TRANSFER)
            ->where('pickup_station_id', $job->pickup_station_id)->where('dropoff_station_id', $job->dropoff_station_id);
    }

    /** A rider's current trips: each door job is one; station-to-station parcels on one route are one together. */
    private function activeTrips(User $rider): Collection
    {
        return DeliveryJob::where('rider_id', $rider->id)->whereIn('status', [DeliveryJob::ASSIGNED, DeliveryJob::PICKED_UP])->with('order')->get()
            ->map(fn (DeliveryJob $j) => $this->tripKey($j))->unique()->values();
    }

    private function tripKey(DeliveryJob $job): string
    {
        return match (true) {
            $job->isTransfer() => "route:{$job->pickup_station_id}-{$job->dropoff_station_id}",
            $job->order?->team_order_id !== null => "team:{$job->order->team_order_id}", // one office order: one trip
            default => "job:{$job->id}",
        };
    }

    /** The rider gives the job back before collecting it. */
    public function release(DeliveryJob $job, User $rider): DeliveryJob
    {
        return $this->step($job, $rider, [DeliveryJob::ASSIGNED], function (DeliveryJob $job) {
            $job->forceFill(['status' => DeliveryJob::OPEN, 'rider_id' => null, 'assigned_at' => null, 'notified_km' => null])->save();
            app(RiderTrackingService::class)->forget($job);
        });
    }

    /** The goods left the shop with the rider: the order is shipped and the buyer gets the handover code. */
    public function pickedUp(DeliveryJob $job, User $rider): DeliveryJob
    {
        return $this->step($job, $rider, [DeliveryJob::ASSIGNED], function (DeliveryJob $job) use ($rider) {
            // Station to station: the receiving station confirms it, there is no buyer code.
            if ($job->isTransfer()) {
                $job->forceFill(['status' => DeliveryJob::PICKED_UP, 'picked_up_at' => now()])->save();

                return;
            }

            $code = (string) random_int(1000, 9999);
            $job->forceFill(['status' => DeliveryJob::PICKED_UP, 'picked_up_at' => now(), 'code_hash' => Hash::make($code), 'code_attempts' => 0])->save();

            // From a station the order is already "shipped" (the station received it).
            if ($job->kind === DeliveryJob::KIND_DOOR) {
                $this->orders->ship($job->order, "WiBuy rider {$rider->name}, {$rider->display_phone}");
            }

            $this->sms->send($job->order->buyer->phone, "WiBuy: Your order {$job->order->number} is on its way with rider {$rider->name}. Give the rider this code only when you have your goods: {$code}");

            // Ordered for family or a friend: the person receiving it needs the code.
            if ($job->order->recipientIsSomeoneElse()) {
                $this->sms->send($job->order->recipient_phone, "WiBuy: {$job->order->buyer->name} sent you order {$job->order->number}. It is on its way with rider {$rider->name} ({$rider->display_phone}). Give the rider this code only when you have the goods: {$code}");
            }
        });
    }

    /** The buyer gave the code: delivered. */
    public function deliverWithCode(DeliveryJob $job, User $rider, string $code): DeliveryJob
    {
        $wrong = false;

        try {
            return $this->step($job, $rider, [DeliveryJob::PICKED_UP], function (DeliveryJob $job) use ($code, &$wrong) {
                $this->refuseTransfer($job);

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
            $this->refuseTransfer($job);
            $this->images->attach($job, $photo, 'delivery', $rider->id);
            $this->finish($job, 'photo', $note);
        });
    }

    /** The seller no longer needs a rider (before collection). */
    public function cancel(DeliveryJob $job, User $sellerUser): DeliveryJob
    {
        if ($sellerUser->seller?->id !== $job->seller_id) {
            throw new MarketplaceException('This is not your order.');
        }

        return $this->step($job, null, [DeliveryJob::OPEN, DeliveryJob::ASSIGNED], function (DeliveryJob $job) {
            if ($job->rider) {
                $this->sms->send($job->rider->phone, "WiBuy: The shop cancelled the delivery of order {$job->order->number}. You do not need to collect it.");
            }

            $job->forceFill(['status' => DeliveryJob::CANCELLED])->save();
            app(RiderTrackingService::class)->forget($job);
        });
    }

    /**
     * The buyer rates the rider who brought the order to their door (once).
     *
     * @throws MarketplaceException
     */
    public function rate(Order $order, User $buyer, int $stars, ?string $comment): DeliveryJob
    {
        $job = DeliveryJob::where('order_id', $order->id)->first();

        if ($order->buyer_id !== $buyer->id || ! $job || ! $job->rider_id || $job->status !== DeliveryJob::DELIVERED || $job->isTransfer()) {
            throw new MarketplaceException('You can rate the rider after a WiBuy rider has delivered your order.');
        }

        if ($stars < 1 || $stars > 5) {
            throw new MarketplaceException('Give 1 to 5 stars.');
        }

        if ($job->rating !== null) {
            throw new MarketplaceException('You already rated this rider. Thank you!');
        }

        $job->forceFill(['rating' => $stars, 'rating_comment' => $comment ? mb_substr(trim($comment), 0, 300) : null])->save();

        return $job;
    }

    /** A rider's average stars and how many ratings (null when none yet). @return ?array{average: float, count: int} */
    public static function riderRating(int $riderId): ?array
    {
        $row = DeliveryJob::where('rider_id', $riderId)->whereNotNull('rating')->selectRaw('count(*) as n, avg(rating) as avg')->first();

        return (int) $row->n > 0 ? ['average' => round((float) $row->avg, 1), 'count' => (int) $row->n] : null;
    }

    /** @throws MarketplaceException */
    private function refuseTransfer(DeliveryJob $job): void
    {
        if ($job->isTransfer()) {
            throw new MarketplaceException("Hand it to the staff at WiBuy station {$job->dropoffStation->name}. They confirm they received it.");
        }
    }

    private function finish(DeliveryJob $job, string $proof, ?string $note): void
    {
        $job->forceFill(['status' => DeliveryJob::DELIVERED, 'delivered_at' => now(), 'proof' => $proof, 'proof_note' => $note])->save();
        app(RiderTrackingService::class)->forget($job);
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
            $locked = DeliveryJob::lockForUpdate()->with('order.buyer', 'order.seller.user', 'rider', 'seller', 'pickupStation.partner', 'dropoffStation')->findOrFail($job->id);

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
