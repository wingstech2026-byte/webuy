<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\DeliveryJob;
use App\Models\Order;
use App\Models\Seller;
use App\Models\TeamOrder;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Instant food delivery around offices. Food shops say when they are open and how long cooking takes. Buyers pay
 * by mobile money first; the shop accepts within 10 minutes and cooks; "Food ready" calls a WiBuy rider and tells
 * the nearest available riders. Orders can also be scheduled ("1:00 pm at the office").
 */
class InstantService
{
    public const ACCEPT_MINUTES = 10;

    public const DEFAULT_RADIUS_KM = 5;

    public const RIDER_RADIUS_KM = 8;

    public const RIDERS_TOLD = 5;

    public const AVAILABLE_HOURS = 3;

    public function __construct(private GeoService $geo, private NotificationService $notifications, private DeliveryService $deliveries, private SmsService $sms) {}

    /**
     * Food shops that deliver to this point: open ones first, then nearest.
     *
     * @return Collection<int, array{seller: Seller, distance_km: float, eta_minutes: int, is_open: bool}>
     */
    public function nearby(float $lat, float $lng): Collection
    {
        $this->geo->assertValid($lat, $lng);

        return $this->geo->nearbySellers($lat, $lng, 15)
            ->filter(fn (array $row) => $row['seller']->is_instant && $row['location']->delivery_enabled
                && $row['distance_km'] <= (float) ($row['location']->delivery_radius_km ?? self::DEFAULT_RADIUS_KM))
            ->map(fn (array $row) => [
                'seller' => $row['seller'],
                'distance_km' => $row['distance_km'],
                'eta_minutes' => $row['seller']->instantEtaMinutes($row['distance_km']),
                'is_open' => $row['seller']->isOpenAt() && ! $row['seller']->isOnHoliday(),
            ])
            ->sortBy([['is_open', 'desc'], ['distance_km', 'asc']])
            ->values();
    }

    /**
     * Instant food needs the exact spot (for the rider) and an open shop, or a time when it is open.
     *
     * @throws MarketplaceException
     */
    public function assertCanOrder(Seller $seller, ?array $point, ?CarbonInterface $deliverAt): void
    {
        $hours = $seller->hoursLabel() ? " (open {$seller->hoursLabel()})" : '';

        if ($point === null) {
            throw new MarketplaceException("{$seller->shop_name} delivers food fast to your exact spot: share your location, or choose a saved address with a map pin.");
        }

        if ($deliverAt === null) {
            if (! $seller->isOpenAt()) {
                throw new MarketplaceException("{$seller->shop_name} is closed now{$hours}. Choose a delivery time when it is open.");
            }

            return;
        }

        $earliest = now()->addMinutes($seller->prep_minutes + 20);

        if ($deliverAt->lt($earliest)) {
            throw new MarketplaceException('Choose a delivery time from '.$earliest->format('H:i').', or order now without a time.');
        }

        if ($deliverAt->gt(now()->addDays(2))) {
            throw new MarketplaceException('You can schedule food up to 2 days ahead.');
        }

        if (! $seller->isOpenAt($deliverAt)) {
            throw new MarketplaceException("{$seller->shop_name} is closed at that time{$hours}.");
        }
    }

    /** After payment: now-orders must be accepted fast (the food is cooked now); scheduled ones before cooking time. */
    public function acceptBy(Order $order): CarbonInterface
    {
        if ($order->deliver_at === null) {
            return now()->addMinutes(self::ACCEPT_MINUTES);
        }

        $cookBy = $order->deliver_at->copy()->subMinutes($order->seller->prep_minutes);

        return now()->addHour()->min($cookBy);
    }

    public function onPaid(Order $order): void
    {
        $when = $order->deliver_at ? 'for '.$order->deliver_at->format('D H:i') : 'now: accept within '.self::ACCEPT_MINUTES.' minutes and start cooking';
        $this->notifications->notify($order->seller->user, '⚡ Instant food order', "Order {$order->number} {$when}. When it is packed, tap \"Food ready\" and a WiBuy rider comes.", route('seller.orders.show', $order));
    }

    /**
     * Cooked and packed: a WiBuy rider is called and the nearest available riders are told. An office order goes
     * together: every colleague's accepted order from this shop is called in the same trip.
     *
     * @throws MarketplaceException
     */
    public function foodReady(Order $order, User $sellerUser): DeliveryJob
    {
        if (! $order->is_instant) {
            throw new MarketplaceException('This is not an instant food order.');
        }

        if ($order->food_ready_at !== null) {
            throw new MarketplaceException('This order is already marked ready.');
        }

        $orders = $order->team_order_id
            ? Order::where('team_order_id', $order->team_order_id)->where('seller_id', $order->seller_id)
                ->where('status', OrderStatus::Accepted)->whereNull('food_ready_at')->orderBy('id')->get()
            : collect([$order]);

        if (! $orders->contains('id', $order->id)) {
            throw new MarketplaceException('Accept the order first.');
        }

        $jobs = $orders->map(function (Order $o) use ($sellerUser) {
            $job = $this->deliveries->request($o, $sellerUser);
            $o->forceFill(['food_ready_at' => now()])->save();
            $this->notifications->notify($o->buyer, '🍲 Your food is ready', "{$o->seller->shop_name} packed order {$o->number}. A WiBuy rider is coming for it.", route('orders.show', $o));

            return $job;
        });

        $this->callRiders($jobs->first(), $jobs->count(), Money::sum($jobs->pluck('fee')));

        return $jobs->firstWhere('order_id', $order->id);
    }

    /** Tell the nearest riders who said they are available (one push each; the first to accept takes it). */
    private function callRiders(DeliveryJob $job, int $parcels, string $fee): void
    {
        if ($job->pickup_latitude === null) {
            return; // no map point: the job still shows first in every rider's list
        }

        User::where('is_rider', true)->where('rider_available_until', '>', now())->whereNotNull('rider_available_lat')->get()
            ->map(fn (User $rider) => ['rider' => $rider, 'km' => round($this->geo->distanceKm((float) $rider->rider_available_lat, (float) $rider->rider_available_lng, (float) $job->pickup_latitude, (float) $job->pickup_longitude), 1)])
            ->filter(fn (array $row) => $row['km'] <= self::RIDER_RADIUS_KM)
            ->sortBy('km')->take(self::RIDERS_TOLD)
            ->each(fn (array $row) => $this->notifications->notify($row['rider'], '⚡ Food delivery near you',
                "{$job->pickup_name}, {$row['km']} km away: ".($parcels > 1 ? "{$parcels} parcels in one trip" : 'one parcel').', fee '.Money::format($fee).'. The first rider to accept takes it.',
                route('rider.jobs.show', $job)));
    }

    /**
     * "I'm available" (with where the rider is now) or "I'm off" (null). The point is kept a few hours at most.
     *
     * @throws MarketplaceException
     */
    public function setAvailable(User $rider, ?float $lat, ?float $lng): void
    {
        if (! $rider->is_rider) {
            throw new MarketplaceException('Only WiBuy riders can do this.');
        }

        if ($lat === null || $lng === null) {
            $rider->forceFill(['rider_available_until' => null, 'rider_available_lat' => null, 'rider_available_lng' => null])->save();

            return;
        }

        $this->geo->assertValid($lat, $lng);
        $rider->forceFill(['rider_available_until' => now()->addHours(self::AVAILABLE_HOURS), 'rider_available_lat' => $lat, 'rider_available_lng' => $lng])->save();
    }

    /**
     * Every minute: remind shops to cook scheduled food, close office orders whose time is up, forget riders' spots.
     *
     * @return array{reminded: int, closed: int}
     */
    public function tick(): array
    {
        $reminded = 0;

        Order::with('seller.user')->where('is_instant', true)->where('status', OrderStatus::Accepted)
            ->whereNotNull('deliver_at')->whereNull('food_ready_at')->whereNull('cook_reminded_at')
            ->where('deliver_at', '<=', now()->addHours(3))->get()
            ->filter(fn (Order $o) => $o->deliver_at->copy()->subMinutes($o->seller->prep_minutes + 15)->lte(now()))
            ->each(function (Order $o) use (&$reminded) {
                $o->forceFill(['cook_reminded_at' => now()])->save();
                $text = "Order {$o->number} must arrive at {$o->deliver_at->format('H:i')}. Start cooking now and tap \"Food ready\" when it is packed.";
                $this->notifications->notify($o->seller->user, '⏰ Start cooking', $text, route('seller.orders.show', $o));
                $this->sms->send($o->seller->user->phone, "WiBuy: {$text}");
                $reminded++;
            });

        $closed = TeamOrder::where('status', TeamOrder::OPEN)->where('closes_at', '<=', now())->update(['status' => TeamOrder::CLOSED]);

        User::whereNotNull('rider_available_lat')->where('rider_available_until', '<=', now())
            ->update(['rider_available_until' => null, 'rider_available_lat' => null, 'rider_available_lng' => null]);

        return ['reminded' => $reminded, 'closed' => $closed];
    }
}
