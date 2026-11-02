<?php

namespace App\Services;

use App\Enums\DeliveryMethod;
use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\DeliveryJob;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Seller;
use App\Models\Station;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * WiBuy pickup stations, run by partner shops.
 *  1. The buyer chooses at checkout: collect at a station (any station; a rider carries it there when it is not
 *     the shop's station), or delivery from the station to their door. They pay the station fees.
 *  2. The seller accepts, then drops the parcel at the station nearest to the shop. The partner confirms it.
 *  3. A rider carries it to the buyer's station (the partner there confirms it) or to the buyer's door.
 *  4. At the station the buyer shows the code sent by SMS and takes the parcel: the order is "delivered".
 *  5. When the order completes, the partners get their share and the riders their fee.
 */
class StationService
{
    public const MAX_CODE_ATTEMPTS = 5;

    public function __construct(private OrderService $orders, private SmsService $sms, private GeoService $geo) {}

    /** The station where a shop drops its parcels: the nearest one to the shop, or one in its district. */
    public function dropStationFor(Seller $seller): ?Station
    {
        $seller->loadMissing('locations');
        $shop = $seller->locations->where('is_active', true)->sortBy('is_residence')->first();
        $stations = Station::active()->get();

        if ($shop && $shop->latitude !== null) {
            $nearest = $stations
                ->map(fn (Station $s) => [$s, $this->geo->distanceKm((float) $shop->latitude, (float) $shop->longitude, (float) $s->latitude, (float) $s->longitude)])
                ->filter(fn (array $pair) => $pair[1] <= RewardSettings::int('station_max_km'))
                ->sortBy(fn (array $pair) => $pair[1])
                ->first();

            if ($nearest) {
                return $nearest[0];
            }
        }

        return $stations->firstWhere('district', $seller->district);
    }

    /**
     * Stations a buyer can choose: nearest first when we know where they are, else their district first.
     *
     * @return Collection<int, Station>
     */
    public function stationsFor(?string $district = null, ?float $lat = null, ?float $lng = null): Collection
    {
        $stations = Station::active()->orderBy('name')->get();

        return ($lat !== null && $lng !== null
            ? $stations->sortBy(fn (Station $s) => $this->geo->distanceKm($lat, $lng, (float) $s->latitude, (float) $s->longitude))
            : $stations->sortBy(fn (Station $s) => $s->district === $district ? 0 : 1))->values();
    }

    /** What the buyer pays for a station choice. */
    public function fee(DeliveryMethod $method, Station $drop, ?Station $collect): string
    {
        $fee = RewardSettings::money('station_fee');

        if ($method === DeliveryMethod::StationDoor) {
            return Money::add($fee, RewardSettings::money('station_door_fee'));
        }

        return $collect && $collect->id !== $drop->id ? Money::add($fee, RewardSettings::money('station_transfer_fee')) : $fee;
    }

    /**
     * The partner received the parcel from the shop: the order is "at the WiBuy station" and moves on.
     *
     * @throws MarketplaceException
     */
    public function receiveFromShop(Order $order, Station $station): Order
    {
        return DB::transaction(function () use ($order, $station) {
            $order = Order::lockForUpdate()->with('buyer', 'seller', 'dropStation', 'collectStation')->findOrFail($order->id);

            if (! $order->viaStation() || $order->drop_station_id !== $station->id) {
                throw new MarketplaceException("Order {$order->number} is not for this station.");
            }

            if ($order->status !== OrderStatus::Accepted || $order->at_drop_station_at) {
                throw new MarketplaceException("Order {$order->number} is \"{$order->status->label()}\": it cannot be received now.");
            }

            $order->forceFill(['at_drop_station_at' => now()])->save();
            $this->orders->ship($order, "At WiBuy station {$station->name}");

            match (true) {
                $order->delivery_method === DeliveryMethod::StationDoor => $this->newJob($order, $station, null, DeliveryJob::KIND_STATION_DOOR, RewardSettings::money('station_door_fee')),
                $order->collect_station_id !== $station->id => $this->newJob($order, $station, $order->collectStation, DeliveryJob::KIND_TRANSFER, RewardSettings::money('station_transfer_fee')),
                default => $this->readyForCollection($order),
            };

            return $order->refresh();
        });
    }

    /**
     * The partner at the buyer's station received the parcel from the rider.
     *
     * @throws MarketplaceException
     */
    public function receiveFromRider(Order $order, Station $station): Order
    {
        return DB::transaction(function () use ($order, $station) {
            $order = Order::lockForUpdate()->with('buyer', 'collectStation')->findOrFail($order->id);
            $job = DeliveryJob::lockForUpdate()->where('order_id', $order->id)->where('kind', DeliveryJob::KIND_TRANSFER)->first();

            if (! $job || $order->collect_station_id !== $station->id) {
                throw new MarketplaceException("Order {$order->number} is not coming to this station.");
            }

            if ($job->status !== DeliveryJob::PICKED_UP) {
                throw new MarketplaceException("The rider has not collected order {$order->number} yet.");
            }

            $job->forceFill(['status' => DeliveryJob::DELIVERED, 'delivered_at' => now(), 'proof' => 'station'])->save();
            app(RiderTrackingService::class)->forget($job);
            $this->readyForCollection($order);

            return $order->refresh();
        });
    }

    /**
     * The buyer collects at the station with the code from the SMS. Wrong codes are counted (5 tries).
     *
     * @throws MarketplaceException
     */
    public function handToBuyer(Order $order, Station $station, string $code): Order
    {
        $wrong = false;

        try {
            return DB::transaction(function () use ($order, $station, $code, &$wrong) {
                $order = Order::lockForUpdate()->findOrFail($order->id);

                if ($order->delivery_method !== DeliveryMethod::Station || $order->collect_station_id !== $station->id || ! $order->at_collect_station_at) {
                    throw new MarketplaceException("Order {$order->number} is not waiting at this station.");
                }

                if ($order->status !== OrderStatus::Shipped) {
                    throw new MarketplaceException("Order {$order->number} is \"{$order->status->label()}\".");
                }

                if ($order->collect_code_attempts >= self::MAX_CODE_ATTEMPTS) {
                    throw new MarketplaceException('Too many wrong codes. The buyer should call WiBuy support.');
                }

                if (! Hash::check(trim($code), (string) $order->collect_code_hash)) {
                    $wrong = true;

                    throw new MarketplaceException('That code is not right. Ask the buyer to check the SMS from WiBuy.');
                }

                return $this->orders->markDelivered($order);
            });
        } catch (MarketplaceException $e) {
            // Counted outside the rolled-back step, so guessing stops after a few tries.
            if ($wrong) {
                Order::whereKey($order->id)->increment('collect_code_attempts');
            }

            throw $e;
        }
    }

    /**
     * The order completed: pay the partner stations their share and the riders their fee (from the station fees
     * the buyer paid; the seller is not paid the station fees). Called with the order locked; once per order.
     */
    public function creditOnCompletion(Order $order): void
    {
        if (! $order->viaStation() || $order->station_fees_credited_at) {
            return;
        }

        $order->loadMissing('dropStation', 'collectStation');
        $share = RewardSettings::money('station_partner_share');
        $partners = collect([$order->dropStation, $order->collectStation])->filter()->unique('id');

        foreach ($partners as $station) {
            $this->credit($station->user_id, $share, LedgerType::StationFee, "Station {$station->name}: order {$order->number}");
        }

        foreach (DeliveryJob::where('order_id', $order->id)->where('status', DeliveryJob::DELIVERED)->whereNotNull('rider_id')->get() as $job) {
            $this->credit($job->rider_id, $job->fee, LedgerType::DeliveryFee, ($job->isTransfer() ? 'Station to station' : 'Station to door').": order {$order->number}");
        }

        $order->forceFill(['station_fees_credited_at' => now()])->save();
    }

    private function credit(int $userId, string $amount, LedgerType $type, string $description): void
    {
        if (Money::cmp($amount, '0') > 0) {
            // No order_id: one order pays several people (the order is in the description).
            LedgerEntry::create(['rider_id' => $userId, 'type' => $type, 'amount' => Money::from($amount), 'description' => $description]);
        }
    }

    private function newJob(Order $order, Station $from, ?Station $to, string $kind, string $fee): DeliveryJob
    {
        return DeliveryJob::create([
            'order_id' => $order->id,
            'seller_id' => $order->seller_id,
            'status' => DeliveryJob::OPEN,
            'kind' => $kind,
            'pickup_station_id' => $from->id,
            'dropoff_station_id' => $to?->id,
            'pickup_name' => "WiBuy station {$from->name}",
            'pickup_address' => $from->address,
            'pickup_latitude' => $from->latitude,
            'pickup_longitude' => $from->longitude,
            'dropoff_district' => $to?->district ?? $order->district,
            'dropoff_latitude' => $to ? $to->latitude : $order->delivery_latitude,
            'dropoff_longitude' => $to ? $to->longitude : $order->delivery_longitude,
            'fee' => $fee,
        ]);
    }

    /** At the buyer's station: a fresh code by SMS. */
    private function readyForCollection(Order $order): void
    {
        $code = (string) random_int(1000, 9999);
        $station = $order->collectStation;
        $order->forceFill(['at_collect_station_at' => now(), 'collect_code_hash' => Hash::make($code), 'collect_code_attempts' => 0])->save();

        $this->sms->send($order->buyer->phone, "WiBuy: Your order {$order->number} is ready at {$station->name}, {$station->address}"
            .($station->opening_hours ? " ({$station->opening_hours})" : '').". Show this code to collect it: {$code}");

        // Ordered for family or a friend: the person collecting it needs the code.
        if ($order->recipientIsSomeoneElse()) {
            $this->sms->send($order->recipient_phone, "WiBuy: {$order->buyer->name} sent you order {$order->number}. Collect it at {$station->name}, {$station->address}. Show this code: {$code}");
        }
    }
}
