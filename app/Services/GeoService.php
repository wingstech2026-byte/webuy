<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\Seller;
use App\Models\SellerLocation;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Everything about places in one service: coordinate checks, distances, whether a seller delivers to a
 * point or district and for how much, nearby sellers, and (when ROUTING_URL is set) road distances.
 * Fees and distances are always worked out here on the server, never trusted from the app.
 */
class GeoService
{
    /** Sierra Leone with a small margin. Coordinates outside are rejected. */
    private const BOUNDS = ['lat_min' => 6.7, 'lat_max' => 10.1, 'lng_min' => -13.5, 'lng_max' => -10.2];

    /** Straight lines are shorter than roads; road distance is estimated as straight-line x 1.3. */
    private const ROAD_FACTOR = 1.3;

    public function isValid(mixed $lat, mixed $lng): bool
    {
        return is_numeric($lat) && is_numeric($lng)
            && (float) $lat >= self::BOUNDS['lat_min'] && (float) $lat <= self::BOUNDS['lat_max']
            && (float) $lng >= self::BOUNDS['lng_min'] && (float) $lng <= self::BOUNDS['lng_max'];
    }

    /** @throws MarketplaceException */
    public function assertValid(mixed $lat, mixed $lng): void
    {
        if (! $this->isValid($lat, $lng)) {
            throw new MarketplaceException('That map position is not in Sierra Leone. Move the pin or type the address instead.');
        }
    }

    /** Straight-line (great-circle) distance in km. */
    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Road distance and time when a routing service is set up (ROUTING_URL, an OSRM-compatible server),
     * otherwise an estimate. Always labelled: it is never a promise.
     *
     * @return array{km: float, minutes: ?int, source: string}
     */
    public function roadEstimate(float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        $straight = $this->distanceKm($lat1, $lng1, $lat2, $lng2);
        $url = config('services.routing.url');

        if ($url) {
            $key = 'route:'.md5(implode(',', array_map(fn ($v) => round($v, 4), [$lat1, $lng1, $lat2, $lng2])));

            try {
                $route = Cache::remember($key, 86400, function () use ($url, $lat1, $lng1, $lat2, $lng2) {
                    $response = Http::timeout(5)->get(rtrim($url, '/')."/route/v1/driving/{$lng1},{$lat1};{$lng2},{$lat2}", ['overview' => 'false'])->throw();

                    return $response->json('routes.0');
                });

                if (isset($route['distance'])) {
                    return ['km' => round($route['distance'] / 1000, 1), 'minutes' => (int) round($route['duration'] / 60), 'source' => 'routing'];
                }
            } catch (Throwable $e) {
                report($e); // the shop keeps working with the estimate
            }
        }

        return ['km' => round($straight * self::ROAD_FACTOR, 1), 'minutes' => null, 'source' => 'estimate'];
    }

    /**
     * Can this seller deliver to this place, and for how much? A point (coordinates) uses the seller's
     * locations with a delivery radius; otherwise (or when no radius covers it) the district zones.
     *
     * @return array{can_deliver: bool, fee: ?string, distance_km: ?float, zone: ?string, window: ?string, min_order: ?string, reason: ?string}
     */
    public function deliveryQuote(Seller $seller, ?string $district, ?float $lat = null, ?float $lng = null, ?string $subtotal = null): array
    {
        $none = ['can_deliver' => false, 'fee' => null, 'distance_km' => null, 'zone' => null, 'window' => null, 'min_order' => null, 'reason' => null];

        if (! $seller->offers_delivery) {
            return ['reason' => 'This shop does not deliver. You can pick up.'] + $none;
        }

        if ($lat !== null && $lng !== null && $this->isValid($lat, $lng)) {
            $best = $this->locationsOf($seller)
                ->filter(fn (SellerLocation $l) => $l->delivery_enabled && $l->delivery_radius_km !== null)
                ->map(fn (SellerLocation $l) => [$l, $this->distanceKm((float) $l->latitude, (float) $l->longitude, $lat, $lng)])
                ->filter(fn ($pair) => $pair[1] <= (float) $pair[0]->delivery_radius_km)
                ->sortBy(fn ($pair) => $pair[1])->first();

            if ($best) {
                [$location, $km] = $best;
                $fee = Money::add($location->base_fee, bcmul(Money::from($location->per_km_fee), number_format(round($km, 1), 1, '.', ''), 2));

                return $this->withMinimum([
                    'can_deliver' => true,
                    'fee' => $fee,
                    'distance_km' => round($km, 1),
                    'zone' => "Within {$location->delivery_radius_km} km of {$location->public_name}",
                    'window' => $location->delivery_window,
                    'min_order' => $location->min_order_value !== null ? Money::from($location->min_order_value) : null,
                    'reason' => null,
                ], $subtotal);
            }
        }

        $area = $district ? ($seller->relationLoaded('deliveryAreas') ? $seller->deliveryAreas : $seller->deliveryAreas()->get())->firstWhere('district', $district) : null;

        if (! $area) {
            return ['reason' => $district ? "{$seller->shop_name} does not deliver to {$district}." : 'Choose your district or address to see delivery.'] + $none;
        }

        return $this->withMinimum([
            'can_deliver' => true,
            'fee' => Money::from($area->fee),
            'distance_km' => null,
            'zone' => $district,
            'window' => $area->delivery_window,
            'min_order' => $area->min_order_value !== null ? Money::from($area->min_order_value) : null,
            'reason' => null,
        ], $subtotal);
    }

    private function withMinimum(array $quote, ?string $subtotal): array
    {
        if ($subtotal !== null && $quote['min_order'] !== null && Money::cmp($subtotal, $quote['min_order']) < 0) {
            $quote['can_deliver'] = false;
            $quote['reason'] = 'Delivery here needs an order of at least '.Money::format($quote['min_order']).'. Add more or choose pickup.';
        }

        return $quote;
    }

    /** @return Collection<int, SellerLocation> */
    private function locationsOf(Seller $seller): Collection
    {
        return $seller->relationLoaded('locations') ? $seller->locations->where('is_active', true) : $seller->locations()->active()->get();
    }

    /**
     * Approved shops with a location within $radiusKm, nearest first. Distances are rounded to 0.1 km and
     * measured to the place buyers may see (approximate for home businesses).
     *
     * @return Collection<int, array{seller: Seller, location: SellerLocation, distance_km: float}>
     */
    public function nearbySellers(float $lat, float $lng, float $radiusKm = 25): Collection
    {
        // A bounding box first (cheap, uses the index), then the exact distance in PHP.
        $dLat = $radiusKm / 111.0;
        $dLng = $radiusKm / (111.0 * max(0.2, cos(deg2rad($lat))));

        return SellerLocation::active()
            ->whereBetween('latitude', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('longitude', [$lng - $dLng, $lng + $dLng])
            ->whereHas('seller', fn ($q) => $q->approved())
            ->with('seller.logo')->get()
            ->map(function (SellerLocation $location) use ($lat, $lng) {
                $point = $location->publicPoint();

                return ['seller' => $location->seller, 'location' => $location, 'distance_km' => round($this->distanceKm($point['latitude'], $point['longitude'], $lat, $lng), 1)];
            })
            ->filter(fn ($row) => $row['distance_km'] <= $radiusKm)
            ->sortBy('distance_km')
            ->unique(fn ($row) => $row['seller']->id) // the nearest place of each shop
            ->values();
    }
}
