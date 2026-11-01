<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\RiderController as WebRiderController;
use App\Models\DeliveryJob;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rider deliveries in the app: open jobs, my jobs, and each step (photo proof is a multipart upload). */
class RiderController extends Controller
{
    public function __construct(private DeliveryService $deliveries) {}

    public function jobs(Request $request): JsonResponse
    {
        $rider = $request->user();
        abort_unless($rider->is_rider, 403, 'This is for WiBuy riders.');
        $district = $request->query('district');

        $open = DeliveryJob::where('status', DeliveryJob::OPEN)->when($district, fn ($q) => $q->where('dropoff_district', $district))->with('order')->latest('id')->limit(50)->get();
        $mine = DeliveryJob::where('rider_id', $rider->id)->whereIn('status', [DeliveryJob::ASSIGNED, DeliveryJob::PICKED_UP])->with('order.items')->latest('id')->get();
        $done = DeliveryJob::where('rider_id', $rider->id)->where('status', DeliveryJob::DELIVERED);

        return response()->json([
            'open' => $open->map(fn ($j) => WebRiderController::present($j, $rider))->values(),
            'mine' => $mine->map(fn ($j) => WebRiderController::present($j, $rider))->values(),
            'delivered_count' => (clone $done)->count(),
            'delivered_fees' => (string) ((clone $done)->sum('fee') ?: '0.00'),
        ]);
    }

    public function act(Request $request, DeliveryJob $deliveryJob, string $action): JsonResponse
    {
        $rider = $request->user();
        abort_unless($rider->is_rider, 403, 'This is for WiBuy riders.');

        $job = match ($action) {
            'accept' => $this->deliveries->accept($deliveryJob, $rider),
            'release' => $this->deliveries->release($deliveryJob, $rider),
            'picked-up' => $this->deliveries->pickedUp($deliveryJob, $rider),
            'deliver' => $request->hasFile('photo')
                ? $this->deliveries->deliverWithPhoto($deliveryJob, $rider, $request->validate(['photo' => ['image', 'max:8192']])['photo'], $request->string('note')->limit(300)->toString() ?: null)
                : $this->deliveries->deliverWithCode($deliveryJob, $rider, $request->validate(['code' => ['required', 'digits:4']])['code']),
        };

        return response()->json(['data' => WebRiderController::present($job->load('order.items'), $rider)]);
    }
}
