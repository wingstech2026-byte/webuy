<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddressRequest;
use App\Models\SavedAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** A buyer's saved delivery addresses. Only the owner ever sees them. */
class AddressController extends Controller
{
    public static function json(SavedAddress $a): array
    {
        return $a->only(['id', 'label', 'recipient_name', 'recipient_phone', 'district', 'town', 'neighbourhood', 'street', 'landmark', 'directions', 'is_default', 'building', 'floor']) + [
            'latitude' => $a->latitude !== null ? (float) $a->latitude : null,
            'longitude' => $a->longitude !== null ? (float) $a->longitude : null,
            'one_line' => $a->one_line,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->savedAddresses()->get()->map(fn ($a) => self::json($a))]);
    }

    public function store(AddressRequest $request): JsonResponse
    {
        $address = $this->save($request, new SavedAddress(['user_id' => $request->user()->id]));

        return response()->json(['data' => self::json($address)], 201);
    }

    public function update(AddressRequest $request, SavedAddress $address): JsonResponse
    {
        abort_unless($address->user_id === $request->user()->id, 404);

        return response()->json(['data' => self::json($this->save($request, $address))]);
    }

    public function destroy(Request $request, SavedAddress $address): Response
    {
        abort_unless($address->user_id === $request->user()->id, 404);
        $address->delete();

        return response()->noContent();
    }

    private function save(AddressRequest $request, SavedAddress $address): SavedAddress
    {
        return DB::transaction(function () use ($request, $address) {
            $user = $request->user();
            $first = ! $user->savedAddresses()->exists();
            $address->fill($request->safe()->except('is_default'));
            $address->is_default = $request->boolean('is_default') || $first;
            $address->save();

            if ($address->is_default) {
                SavedAddress::where('user_id', $user->id)->whereKeyNot($address->id)->update(['is_default' => false]);
            }

            return $address;
        });
    }
}
