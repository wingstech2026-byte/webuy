<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Operator;
use App\Http\Controllers\Controller;
use App\Http\Controllers\RiderController as WebRiderController;
use App\Http\Requests\RiderPayoutRequest;
use App\Models\DeliveryJob;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Services\DeliveryService;
use App\Services\LedgerService;
use App\Services\PayoutService;
use App\Services\RiderTrackingService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rider deliveries in the app: open jobs, my jobs, each step (photo proof is a multipart upload), and earnings. */
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

    /** Delivery fees, payouts and the latest credits and debits. Former riders can still withdraw what they earned. */
    public function earnings(Request $request, LedgerService $ledger): JsonResponse
    {
        $rider = $request->user();
        abort_unless(WebRiderController::canSeeEarnings($rider), 403, 'This is for WiBuy riders.');
        $last = Payout::where('rider_id', $rider->id)->latest('id')->first();

        return response()->json([
            'money' => $ledger->riderSummary($rider),
            'min_payout' => SettingsService::minPayout(),
            'pay_to' => ['operator' => $last?->operator?->value, 'phone' => $last->phone ?? $rider->phone],
            'payouts' => Payout::where('rider_id', $rider->id)->latest('id')->limit(20)->get()->map(fn (Payout $p) => [
                'id' => $p->id,
                'amount' => (string) $p->amount,
                'status' => $p->status->value,
                'status_label' => $p->status->label(),
                'operator' => $p->operator->label(),
                'phone' => $p->phone,
                'rejection_reason' => $p->rejection_reason,
                'created_at' => $p->created_at?->toIso8601String(),
            ])->values(),
            'entries' => LedgerEntry::where('rider_id', $rider->id)->latest('id')->limit(30)->get()->map(fn (LedgerEntry $e) => [
                'type' => $e->type->label(),
                'description' => $e->description,
                'amount' => (string) $e->amount,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function requestPayout(RiderPayoutRequest $request, PayoutService $payouts): JsonResponse
    {
        $rider = $request->user();
        abort_unless(WebRiderController::canSeeEarnings($rider), 403, 'This is for WiBuy riders.');

        $payout = $payouts->requestForRider($rider, $request->validated('amount'), Operator::from($request->validated('operator')), $request->validated('phone'));

        return response()->json(['data' => ['id' => $payout->id, 'amount' => (string) $payout->amount, 'status' => $payout->status->value]], 201);
    }

    /** The app reports the rider's position while carrying a parcel (only the latest point is kept). */
    public function location(Request $request, DeliveryJob $deliveryJob, RiderTrackingService $tracking): JsonResponse
    {
        $data = $request->validate(['lat' => ['required', 'numeric'], 'lng' => ['required', 'numeric']]);

        return response()->json(['tracking' => $tracking->report($deliveryJob, $request->user(), (float) $data['lat'], (float) $data['lng'])]);
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
