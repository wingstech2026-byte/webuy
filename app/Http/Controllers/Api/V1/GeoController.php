<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SellerResource;
use App\Models\Seller;
use App\Models\SellerLocation;
use App\Services\GeoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Nearby shops and "does this shop deliver to me?". Distances are estimates, never promises. */
class GeoController extends Controller
{
    public function __construct(private GeoService $geo) {}

    private function point(Request $request): array
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:100'],
        ]);
        $this->geo->assertValid($data['lat'], $data['lng']);

        return $data;
    }

    public function nearby(Request $request): JsonResponse
    {
        $p = $this->point($request);

        return response()->json(['data' => $this->geo->nearbySellers((float) $p['lat'], (float) $p['lng'], (float) ($p['radius_km'] ?? 25))
            ->take(30)->map(fn ($row) => [
                'seller' => (new SellerResource($row['seller']))->resolve($request),
                'distance_km' => $row['distance_km'],
                'location' => self::publicLocation($row['location']),
            ])]);
    }

    /** Can this shop deliver to my point or district, how much, and where can I pick up? */
    public function delivery(Request $request, Seller $seller): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'required_with:lat'],
            'district' => ['nullable', 'string', 'max:40'],
        ]);
        $lat = isset($data['lat']) && $this->geo->isValid($data['lat'], $data['lng']) ? (float) $data['lat'] : null;
        $lng = $lat !== null ? (float) $data['lng'] : null;

        $quote = $this->geo->deliveryQuote($seller, $data['district'] ?? null, $lat, $lng);
        $pickups = $seller->locations()->active()->where('pickup_enabled', true)->get();

        return response()->json($quote + [
            'estimate_note' => 'Delivery times are estimates, not guarantees.',
            'road' => $lat !== null && ($nearest = $seller->locations()->active()->first())
                ? $this->geo->roadEstimate((float) $nearest->latitude, (float) $nearest->longitude, $lat, $lng) : null,
            'pickup_points' => $pickups->map(fn ($l) => self::publicLocation($l) + [
                'distance_km' => $lat !== null ? round($this->geo->distanceKm($l->publicPoint()['latitude'], $l->publicPoint()['longitude'], $lat, $lng), 1) : null,
            ])->values(),
        ]);
    }

    /** Only what buyers may see: never the exact pin of a home business. */
    public static function publicLocation(SellerLocation $l): array
    {
        return [
            'id' => $l->id,
            'type' => $l->type,
            'name' => $l->public_name,
            'address' => $l->is_residence ? null : $l->public_address,
            'district' => $l->district,
            'town' => $l->town,
            'verified' => $l->verification_status === 'verified',
            'pickup' => $l->pickup_enabled,
            'pickup_instructions' => $l->pickup_enabled ? $l->pickup_instructions : null,
            'delivery_window' => $l->delivery_window,
        ] + $l->publicPoint();
    }
}
