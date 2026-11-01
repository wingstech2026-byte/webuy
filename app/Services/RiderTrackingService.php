<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\DeliveryJob;
use App\Models\User;

/**
 * Live tracking of a parcel while a WiBuy rider carries it. The rider's phone sends its position every few
 * seconds; only the LATEST position is kept (no route history), only the buyer of that order sees it, only
 * while the parcel is with the rider, and it is wiped when the delivery ends. Buyers and sellers are never
 * tracked. The buyer also gets "about 3 km away" and "less than 1 km away" messages.
 */
class RiderTrackingService
{
    /** An okada or keke in town, used for the arrival estimate (labelled as an estimate). */
    private const KMH = 20;

    private const ROAD_FACTOR = 1.3;

    /** A position older than this is "last seen", not live. */
    public const LIVE_MINUTES = 10;

    /** "X km away" messages, from far to near. */
    private const MESSAGES_AT_KM = [3, 1];

    public function __construct(private GeoService $geo, private NotificationService $notifications) {}

    /**
     * The rider's phone reports where it is. Only the assigned rider, only while carrying the parcel.
     *
     * @return ?array<string, mixed> the tracking the buyer now sees
     *
     * @throws MarketplaceException
     */
    public function report(DeliveryJob $job, User $rider, float $lat, float $lng): ?array
    {
        if ($job->rider_id !== $rider->id) {
            throw new MarketplaceException('This delivery is not yours.');
        }

        if ($job->status !== DeliveryJob::PICKED_UP) {
            throw new MarketplaceException('Your location is only shared while you carry the parcel.');
        }

        $this->geo->assertValid($lat, $lng);
        $job->forceFill(['rider_latitude' => $lat, 'rider_longitude' => $lng, 'rider_located_at' => now()])->save();

        $tracking = $this->tracking($job);
        $this->maybeTellBuyer($job, $tracking);

        return $tracking;
    }

    /**
     * What the buyer sees: the rider's latest point, the destination, distance and arrival estimate.
     * Null when the parcel is not with a rider (or the rider never shared a position).
     *
     * @return ?array{rider: array{lat: float, lng: float}, destination: ?array{lat: float, lng: float}, km: ?float, minutes: ?int, live: bool, updated_at: string, to_station: ?string}
     */
    public function tracking(DeliveryJob $job): ?array
    {
        if ($job->status !== DeliveryJob::PICKED_UP || ! $job->rider_located_at) {
            return null;
        }

        $rider = ['lat' => (float) $job->rider_latitude, 'lng' => (float) $job->rider_longitude];
        $destination = $job->dropoff_latitude !== null ? ['lat' => (float) $job->dropoff_latitude, 'lng' => (float) $job->dropoff_longitude] : null;
        $km = $destination ? round($this->geo->distanceKm($rider['lat'], $rider['lng'], $destination['lat'], $destination['lng']) * self::ROAD_FACTOR, 1) : null;

        return [
            'rider' => $rider,
            'destination' => $destination,
            'km' => $km,
            'minutes' => $km !== null ? max(1, (int) ceil($km / self::KMH * 60)) : null,
            'live' => $job->rider_located_at->gt(now()->subMinutes(self::LIVE_MINUTES)),
            'updated_at' => $job->rider_located_at->toIso8601String(),
            'to_station' => $job->isTransfer() ? $job->dropoffStation?->name : null,
        ];
    }

    /** The delivery ended (delivered, given back, cancelled): forget where the rider is. */
    public function forget(DeliveryJob $job): void
    {
        $job->forceFill(['rider_latitude' => null, 'rider_longitude' => null, 'rider_located_at' => null])->save();
    }

    private function maybeTellBuyer(DeliveryJob $job, ?array $tracking): void
    {
        if ($tracking === null || $tracking['km'] === null) {
            return;
        }

        $threshold = collect(self::MESSAGES_AT_KM)->filter(fn (int $km) => $tracking['km'] <= $km)->min();

        if ($threshold === null || ($job->notified_km !== null && $job->notified_km <= $threshold)) {
            return; // not close enough yet, or this message was already sent
        }

        $job->forceFill(['notified_km' => $threshold])->save();
        $order = $job->order;
        $where = $job->isTransfer() ? "WiBuy station {$job->dropoffStation->name}" : 'you';
        $text = $threshold === 1
            ? "Your order {$order->number} is less than 1 km from {$where}. About {$tracking['minutes']} min."
            : "Your order {$order->number} is about {$tracking['km']} km from {$where}. About {$tracking['minutes']} min.";

        $this->notifications->notify($order->buyer, $threshold === 1 ? '🛵 Almost there!' : '🛵 On the way', $text, route('orders.show', $order));
    }
}
