<?php

namespace App\Http\Controllers;

use App\Enums\Operator;
use App\Exceptions\MarketplaceException;
use App\Http\Requests\RiderPayoutRequest;
use App\Models\DeliveryJob;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\LedgerService;
use App\Services\PayoutService;
use App\Services\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The rider's pages: open jobs near them, their own deliveries, each step of a delivery, and earnings. */
class RiderController extends Controller
{
    public function __construct(private DeliveryService $deliveries) {}

    public function index(Request $request): View
    {
        $rider = $this->rider($request);
        $district = $request->query('district');

        return view('rider.index', [
            'district' => $district,
            'open' => DeliveryJob::where('status', DeliveryJob::OPEN)->when($district, fn ($q) => $q->where('dropoff_district', $district))
                ->latest('id')->limit(50)->get(),
            'mine' => DeliveryJob::where('rider_id', $rider->id)->whereIn('status', [DeliveryJob::ASSIGNED, DeliveryJob::PICKED_UP])->with('order')->latest('id')->get(),
            'done' => DeliveryJob::where('rider_id', $rider->id)->where('status', DeliveryJob::DELIVERED),
        ]);
    }

    public function show(Request $request, DeliveryJob $deliveryJob): View
    {
        $rider = $this->rider($request);
        abort_unless($deliveryJob->status === DeliveryJob::OPEN || $deliveryJob->rider_id === $rider->id, 404);

        return view('rider.show', ['job' => self::present($deliveryJob->load('order.items', 'photos'), $rider), 'model' => $deliveryJob]);
    }

    public function act(Request $request, DeliveryJob $deliveryJob, string $action): RedirectResponse
    {
        $rider = $this->rider($request);

        try {
            match ($action) {
                'accept' => $this->deliveries->accept($deliveryJob, $rider),
                'release' => $this->deliveries->release($deliveryJob, $rider),
                'picked-up' => $this->deliveries->pickedUp($deliveryJob, $rider),
                'deliver' => $request->hasFile('photo')
                    ? $this->deliveries->deliverWithPhoto($deliveryJob, $rider, $request->validate(['photo' => ['image', 'max:8192']])['photo'], $request->string('note')->limit(300)->toString() ?: null)
                    : $this->deliveries->deliverWithCode($deliveryJob, $rider, $request->validate(['code' => ['required', 'digits:4']])['code']),
            };
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return $action === 'release'
            ? redirect()->route('rider.index')->with('status', 'Job given back.')
            : redirect()->route('rider.jobs.show', $deliveryJob)->with('status', 'Done.');
    }

    /** Delivery fees earned, payouts and every credit and debit. Former riders can still withdraw what they earned. */
    public function earnings(Request $request, LedgerService $ledger): View
    {
        $rider = $this->earner($request);
        $last = Payout::where('rider_id', $rider->id)->latest('id')->first();

        return view('rider.earnings', [
            'money' => $ledger->riderSummary($rider),
            'entries' => LedgerEntry::where('rider_id', $rider->id)->with('order')->latest('id')->paginate(15, ['*'], 'ledger')->withQueryString(),
            'payouts' => Payout::where('rider_id', $rider->id)->latest('id')->paginate(10, ['*'], 'payouts')->withQueryString(),
            'minPayout' => SettingsService::minPayout(),
            'payTo' => ['operator' => $last?->operator, 'phone' => $last->phone ?? $rider->phone],
        ]);
    }

    public function requestPayout(RiderPayoutRequest $request, PayoutService $payouts): RedirectResponse
    {
        $rider = $this->earner($request);

        try {
            $payout = $payouts->requestForRider($rider, $request->validated('amount'), Operator::from($request->validated('operator')), $request->validated('phone'));
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('rider.earnings')->with('status', 'Payout requested: '.money($payout->amount).'. We will check it and send it to your mobile money.');
    }

    /** Riders, and former riders who still have delivery fees on their balance. */
    public static function canSeeEarnings(User $user): bool
    {
        return $user->is_rider || LedgerEntry::where('rider_id', $user->id)->exists();
    }

    /**
     * What a rider may see. Before taking a job: areas and the fee only. After: the buyer's address and phone.
     *
     * @return array<string, mixed>
     */
    public static function present(DeliveryJob $job, User $viewer): array
    {
        $mine = $job->rider_id === $viewer->id && $job->status !== DeliveryJob::OPEN;
        $order = $job->order;

        return [
            'id' => $job->id,
            'status' => $job->status,
            'status_label' => $job->statusLabel(),
            'fee' => (string) $job->fee,
            'pickup_name' => $job->pickup_name,
            'pickup_address' => $job->pickup_address,
            'pickup_latitude' => $job->pickup_latitude !== null ? (float) $job->pickup_latitude : null,
            'pickup_longitude' => $job->pickup_longitude !== null ? (float) $job->pickup_longitude : null,
            'dropoff_district' => $job->dropoff_district,
            'is_mine' => $mine,
            'order_number' => $mine ? $order->number : null,
            'items' => $mine && $order->relationLoaded('items') ? $order->items->map(fn ($i) => $i->quantity.' × '.$i->display_title)->implode(', ') : null,
            'buyer_name' => $mine ? $order->recipient_name : null,
            'buyer_phone' => $mine ? $order->recipient_phone : null,
            'dropoff_address' => $mine ? trim(($order->address ?? '').($order->landmark ? ' · '.$order->landmark : '')) : null,
            'dropoff_latitude' => $mine && $job->dropoff_latitude !== null ? (float) $job->dropoff_latitude : null,
            'dropoff_longitude' => $mine && $job->dropoff_longitude !== null ? (float) $job->dropoff_longitude : null,
            'code_attempts_left' => max(0, DeliveryService::MAX_CODE_ATTEMPTS - $job->code_attempts),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    private function earner(Request $request): User
    {
        abort_unless(self::canSeeEarnings($request->user()), 403, 'This page is for WiBuy riders.');

        return $request->user();
    }

    private function rider(Request $request): User
    {
        abort_unless($request->user()->is_rider, 403, 'This page is for WiBuy riders.');

        return $request->user();
    }
}
