<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CheckoutStatus;
use App\Enums\DeliveryMethod;
use App\Enums\Operator;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\Api\V1\CheckoutResource;
use App\Http\Resources\Api\V1\CouponResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\SellerResource;
use App\Models\Checkout;
use App\Models\Coupon;
use App\Models\SavedAddress;
use App\Models\TeamOrder;
use App\Services\CartService;
use App\Services\CheckoutLines;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\PaymentService;
use App\Services\StationService;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Checkout for the app: quote (fees and totals), pay, then poll the payment until it is paid or failed.
 * The same CheckoutService as the website reserves stock and splits the cart into one order per seller.
 */
class CheckoutController extends Controller
{
    public function __construct(private CheckoutLines $lines, private CheckoutService $checkouts, private CouponService $coupons) {}

    /** Items, delivery options and fees per seller for a district, and the grand total. Changes nothing. */
    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product' => ['nullable', 'string', 'max:120'],
            'qty' => ['nullable', 'integer', 'min:1'],
            'variant' => ['nullable', 'integer'],
            'district' => ['nullable', Rule::in(config('wibuy.districts'))],
            'methods' => ['nullable', 'array'],
            'methods.*' => [Rule::enum(DeliveryMethod::class)],
            'coupon_id' => ['nullable', 'integer'],
            'sample' => ['nullable', 'boolean'],
            'address_id' => ['nullable', 'integer'],
            'lat' => ['nullable', 'numeric', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'required_with:lat'],
            'station_id' => ['nullable', 'integer'],
            'team' => ['nullable', 'string', 'max:16'],
            'promo_code' => ['nullable', 'string', 'max:20'],
            'tips' => ['nullable', 'array'],
            'tips.*' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        [$lines, $source] = $this->lines->resolve($request->user(), $data['product'] ?? null, $data['qty'] ?? null, (bool) ($data['sample'] ?? false), $data['variant'] ?? null);
        $user = $request->user();
        $saved = isset($data['address_id']) ? SavedAddress::where('user_id', $user->id)->findOrFail($data['address_id']) : null;
        $district = $saved?->district ?? $data['district'] ?? 'Western Area Urban';
        $point = $saved?->hasPoint() ? ['lat' => (float) $saved->latitude, 'lng' => (float) $saved->longitude]
            : (isset($data['lat']) ? ['lat' => $data['lat'], 'lng' => $data['lng']] : null);
        $coupon = isset($data['coupon_id']) ? Coupon::where('user_id', $user->id)->find($data['coupon_id']) : null;
        // An office team order: delivered to the office (the organiser pays the delivery once).
        $team = isset($data['team']) ? TeamOrder::where('token', $data['team'])->first() : null;

        if ($team) {
            [$district, $point] = [$team->district, ['lat' => (float) $team->latitude, 'lng' => (float) $team->longitude]];
        }

        $summary = $this->checkouts->summarize($lines, $district, $data['methods'] ?? [], $user, $coupon, 'app', $point, $data['station_id'] ?? null, $team,
            ['promo_code' => $data['promo_code'] ?? null, 'tips' => $data['tips'] ?? []]);
        $stations = app(StationService::class)->stationsFor($district, $point['lat'] ?? null, $point['lng'] ?? null);

        return response()->json([
            'source' => $source,
            'district' => $district,
            'groups' => collect($summary['groups'])->map(fn (array $group, int $sellerId): array => [
                'seller' => (new SellerResource($group['seller']))->resolve($request),
                'items' => $group['items']->map(fn (array $item): array => [
                    'product' => (new ProductResource($item['product']))->resolve($request),
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                ])->values(),
                'subtotal' => $group['subtotal'],
                'method' => $group['method']?->value,
                'can_deliver' => $group['can_deliver'],
                'can_pickup' => $group['can_pickup'],
                'district_fee' => $group['district_fee'],
                'delivery_fee' => $group['delivery_fee'],
                'total' => $group['total'],
                'discount' => $group['discount'],
                'delivery_reason' => $group['delivery_reason'],
                'distance_km' => $group['distance_km'],
                'delivery_window' => $group['delivery_window'],
                'free_delivery' => ! Money::isZero($group['pass_discount']),
                // The shop's own free delivery from an amount.
                'shop_free_delivery' => ! Money::isZero($group['shop_pays_delivery']),
                'free_delivery_from' => $group['free_delivery_from'],
                // WiBuy stations (null when the shop has no station near it).
                'can_station' => $group['drop_station'] !== null,
                'station_fee' => $group['station_fee'],
                'station_door_fee' => $group['station_door_fee'],
                // Instant food: delivered in about this many minutes (cooking + the ride), or at a chosen time.
                'is_instant' => (bool) $group['seller']->is_instant,
                'eta_minutes' => $group['seller']->is_instant && $group['distance_km'] !== null ? $group['seller']->instantEtaMinutes((float) $group['distance_km']) : null,
                'is_open' => $group['seller']->isOpenAt(),
                // The shop's own offers (paid by the shop) and the tip for the rider.
                'shop_discount' => $group['shop_discount'],
                'offer_labels' => $group['offer_labels'],
                'rider_tip' => $group['rider_tip'],
            ])->values(),
            'shop_discount_total' => $summary['shop_discount_total'],
            'tips_total' => $summary['tips_total'],
            'promo_error' => $summary['promo_error'],
            'team' => $team ? ['token' => $team->token, 'title' => $team->title, 'place' => $team->place, 'is_open' => $team->isOpen(), 'organiser_pays_delivery' => $team->leader_id !== $user->id] : null,
            // Stations the buyer can collect at, nearest first.
            'stations' => $stations->map(fn ($s): array => ['id' => $s->id, 'name' => $s->name, 'address' => $s->address, 'district' => $s->district, 'opening_hours' => $s->opening_hours])->values(),
            'station_id' => $summary['groups'] ? collect($summary['groups'])->first()['collect_station']?->id : null,
            'items_total' => $summary['items_total'],
            'delivery_total' => $summary['delivery_total'],
            'grand_total' => $summary['grand_total'],
            'discount_total' => $summary['discount_total'],
            'coupon_discount' => $summary['coupon_discount'],
            'coupon_error' => $summary['coupon_error'],
            'pass_savings' => $summary['pass_savings'],
            'is_member' => $summary['is_member'],
            'amount_due' => $summary['amount_due'],
            'coupons' => CouponResource::collection($this->coupons->usable($user, 'app'))->resolve($request),
            // Big orders from one shop can be paid in parts (deposit now, balance before delivery).
            'can_pay_in_parts' => count($summary['groups']) === 1 && Money::gte($summary['amount_due'], RewardSettings::money('milestone_min_amount')),
            'deposit_percent' => RewardSettings::int('milestone_deposit_percent'),
            'deposit_amount' => Money::percentOf($summary['amount_due'], RewardSettings::int('milestone_deposit_percent')),
        ]);
    }

    /** Place the orders and send the mobile money prompt. */
    public function store(CheckoutRequest $request, CartService $cart): JsonResponse
    {
        $data = $request->validated();
        $isBuyNow = $data['source'] === 'buy';
        $user = $request->user();

        [$lines, $source] = $this->lines->resolve($user, $isBuyNow ? ($data['product'] ?? '') : null, $isBuyNow ? ($data['qty'] ?? 1) : null, $isBuyNow && $request->boolean('sample'), $isBuyNow ? ($data['variant'] ?? null) : null);

        $checkout = $this->checkouts->place(
            $user,
            $lines->map(fn (array $line): array => array_filter([
                'product_id' => $line['product']->id,
                'variant_id' => $line['variant']?->id,
                'addon_ids' => isset($line['addons']) ? $line['addons']->pluck('id')->all() : null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'] ?? null,
                'is_sample' => $line['is_sample'] ?? null,
            ], fn ($value) => $value !== null))->all(),
            ['station_id' => $data['station_id'] ?? null, 'deliver_at' => $data['deliver_at'] ?? null]
            + (($team = isset($data['team']) ? TeamOrder::where('token', $data['team'])->first() : null) ? ['team_order_id' => $team->id] : [])
            + ($request->filled('address_id')
                ? GroupBuyController::fromSavedAddress(SavedAddress::where('user_id', $user->id)->findOrFail($request->integer('address_id')))
                : [
                    'name' => $data['recipient_name'] ?? $user->name,
                    'phone' => $data['recipient_phone'] ?? $user->phone,
                    'district' => $data['district'] ?? null,
                    'address' => $data['address'] ?? null,
                    'note' => $data['note'] ?? null,
                    'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
                    'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
                ]),
            $data['methods'],
            Operator::from($data['operator']),
            $data['payer_phone'],
            isset($data['coupon_id']) ? (int) $data['coupon_id'] : null,
            'app',
            ['payment_plan' => $data['payment_plan'] ?? 'full', 'promo_code' => $data['promo_code'] ?? null, 'tips' => $data['tips'] ?? []],
        );

        if ($source === 'cart') {
            $cart->removeProducts($user, $lines->pluck('product.id'));
        }

        return (new CheckoutResource($checkout->load('orders.items', 'orders.seller', 'latestDeposit')))
            ->response()->setStatusCode(201);
    }

    /** Polled by the payment screen every 5 seconds. */
    public function show(Checkout $checkout, PaymentService $payments): CheckoutResource
    {
        abort_if(Gate::denies('view', $checkout), 404);

        $deposit = $checkout->latestDeposit;

        // Safety net if a webhook never arrived: ask the gateway directly, at most every 20 seconds.
        if ($checkout->status === CheckoutStatus::PendingPayment && $deposit?->status === PaymentStatus::Pending
            && $deposit->created_at->lt(now()->subSeconds(30))
            && Cache::add("payment-poll:{$deposit->id}", 1, 20)) {
            $payments->syncQuietly($deposit);
            $checkout->refresh();
        }

        return new CheckoutResource($checkout->load('orders.items', 'orders.seller', 'latestDeposit'));
    }

    public function cancel(Checkout $checkout): CheckoutResource
    {
        abort_if(Gate::denies('view', $checkout), 404);

        $this->checkouts->cancelUnpaid($checkout);

        return new CheckoutResource($checkout->refresh()->load('orders.items', 'orders.seller', 'latestDeposit'));
    }

    /**
     * Development only (PAYMENT_GATEWAY=fake): pretend the phone approved or declined, then sync
     * exactly like the webhook does.
     */
    public function simulate(Request $request, Checkout $checkout, PaymentService $payments): CheckoutResource
    {
        abort_unless(config('payments.gateway') === 'fake' && ! app()->isProduction(), 404);
        abort_if(Gate::denies('view', $checkout), 404);
        $request->validate(['outcome' => ['required', 'in:approve,decline']]);

        $deposit = $checkout->latestDeposit;
        abort_unless($deposit && $deposit->status === PaymentStatus::Pending, 422, 'This payment is already finished.');

        $deposit->forceFill([
            'raw_response' => array_merge($deposit->raw_response ?? [], [
                'fake_status' => $request->input('outcome') === 'approve' ? 'COMPLETED' : 'FAILED',
            ]),
        ])->save();

        $payments->sync($deposit);

        return new CheckoutResource($checkout->refresh()->load('orders.items', 'orders.seller', 'latestDeposit'));
    }
}
