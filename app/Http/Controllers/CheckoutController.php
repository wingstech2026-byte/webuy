<?php

namespace App\Http\Controllers;

use App\Enums\CheckoutStatus;
use App\Enums\Operator;
use App\Enums\PaymentStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Requests\CheckoutRequest;
use App\Models\Checkout;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\TeamOrder;
use App\Models\User;
use App\Services\CartService;
use App\Services\CheckoutLines;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\PaymentService;
use App\Services\StationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CheckoutController extends Controller
{
    public function __construct(private CartService $cart, private CheckoutService $checkouts, private CouponService $coupons) {}

    /**
     * The checkout page. It buys the whole cart, or just one product with ?product=slug&qty=N ("Buy now").
     * Delivery fees follow the chosen district: change it and press "Update" (no JavaScript needed).
     */
    public function show(Request $request)
    {
        $user = $request->user();

        if (! $user->hasBuyerDetails()) {
            $request->session()->put('url.intended', $request->fullUrl()); // back to checkout after saving

            return redirect()->route('account.details')->with('status', 'Before buying, please add your full name and where you live. Your cart is saved.');
        }

        try {
            [$lines, $source] = $this->lines($request, $user);
        } catch (MarketplaceException $e) {
            return redirect()->route('cart.index')->withErrors(['general' => $e->getMessage()]);
        }

        $district = $request->input('district', old('district'));
        $district = in_array($district, config('wibuy.districts'), true) ? $district : 'Western Area Urban';
        $methods = (array) $request->input('methods', old('methods', []));
        $couponId = $request->input('coupon_id', old('coupon_id'));
        $coupon = $couponId ? Coupon::where('user_id', $user->id)->find($couponId) : null;
        $stationId = $request->integer('station_id', (int) old('station_id')) ?: null;
        // Instant food: the buyer's spot (from "Use my location") and an office team order.
        $team = self::team($request->input('team', old('team')));
        $lat = $request->input('latitude', old('latitude'));
        $point = $team ? ['lat' => (float) $team->latitude, 'lng' => (float) $team->longitude]
            : (is_numeric($lat) && is_numeric($request->input('longitude', old('longitude'))) ? ['lat' => (float) $lat, 'lng' => (float) $request->input('longitude', old('longitude'))] : null);
        $district = $team?->district ?? $district;

        return view('checkout.show', [
            'summary' => $this->checkouts->summarize($lines, $district, $methods, $user, $coupon, 'web', $point, $stationId, $team, [
                'promo_code' => $request->input('promo_code', old('promo_code')),
                'tips' => (array) $request->input('tips', old('tips', [])),
            ]),
            'promoCode' => $request->input('promo_code', old('promo_code')),
            'tips' => (array) $request->input('tips', old('tips', [])),
            'point' => $point,
            'team' => $team,
            'stations' => app(StationService::class)->stationsFor($district),
            'stationId' => $stationId,
            'coupons' => $this->coupons->usable($user, 'web'),
            'couponId' => $coupon?->id,
            'source' => $source,
            'product' => $source === 'buy' ? $lines->first()['product'] : null,
            'qty' => $source === 'buy' ? $lines->first()['quantity'] : null,
            'district' => $district,
            'districts' => config('wibuy.districts'),
            'operators' => Operator::cases(),
            'user' => $user,
        ]);
    }

    public function store(CheckoutRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        try {
            [$lines, $source] = $this->lines($request, $user);

            $checkout = $this->checkouts->place(
                $user,
                $lines->map(fn ($line) => ['product_id' => $line['product']->id, 'variant_id' => $line['variant']?->id, 'addon_ids' => ($line['addons'] ?? collect())->pluck('id')->all(), 'quantity' => $line['quantity']])->all(),
                [
                    'name' => $data['recipient_name'] ?? $user->name,
                    'phone' => $data['recipient_phone'] ?? $user->phone,
                    'district' => $data['district'] ?? null,
                    'address' => $data['address'] ?? null,
                    'note' => $data['note'] ?? null,
                    'station_id' => $data['station_id'] ?? null,
                    'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
                    'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
                    'deliver_at' => $data['deliver_at'] ?? null,
                ] + (($team = self::team($data['team'] ?? null)) ? ['team_order_id' => $team->id] : []),
                $data['methods'],
                Operator::from($data['operator']),
                $data['payer_phone'],
                isset($data['coupon_id']) ? (int) $data['coupon_id'] : null,
                'web',
                ['promo_code' => $data['promo_code'] ?? null, 'tips' => $data['tips'] ?? []],
            );
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        if ($source === 'cart') {
            $this->cart->removeProducts($user, $lines->pluck('product.id'));
        }

        return redirect()->route('checkout.status', $checkout);
    }

    private static function team(?string $token): ?TeamOrder
    {
        return $token ? TeamOrder::where('token', $token)->first() : null;
    }

    /** "Approve the payment prompt on your phone": refreshes every 5 seconds until paid or failed. */
    public function status(Checkout $checkout, PaymentService $payments)
    {
        $this->authorize('view', $checkout);

        $checkout->load('orders.items', 'orders.seller', 'latestDeposit');
        $deposit = $checkout->latestDeposit;

        // Safety net if a webhook never arrived: ask the gateway directly, at most every 20 seconds.
        if ($checkout->status === CheckoutStatus::PendingPayment && $deposit?->status === PaymentStatus::Pending
            && $deposit->created_at->lt(now()->subSeconds(30))
            && Cache::add("payment-poll:{$deposit->id}", 1, 20)) {
            $payments->syncQuietly($deposit);
            $checkout->refresh()->load('orders.items', 'orders.seller', 'latestDeposit');
            $deposit = $checkout->latestDeposit;
        }

        return view('checkout.status', [
            'checkout' => $checkout,
            'deposit' => $deposit,
            'fakeGateway' => config('payments.gateway') === 'fake' && ! app()->isProduction(),
        ]);
    }

    /** Give up on an unpaid checkout so the stock goes back and the buyer can start again. */
    public function cancel(Checkout $checkout): RedirectResponse
    {
        $this->authorize('view', $checkout);

        $this->checkouts->cancelUnpaid($checkout);

        return redirect()->route('cart.index')->with('status', 'Checkout cancelled. Nothing was charged.');
    }

    /**
     * The items being bought: "Buy now" (one product) or the whole cart.
     *
     * @return array{0: Collection<int, array{product: Product, quantity: int}>, 1: string}
     *
     * @throws MarketplaceException
     */
    private function lines(Request $request, User $user): array
    {
        $isBuyNow = $request->input('source') === 'buy' || $request->filled('product');

        return app(CheckoutLines::class)->resolve(
            $user,
            $isBuyNow ? (string) $request->input('product') : null,
            $isBuyNow ? (int) $request->input('qty', 1) : null,
            false,
            $isBuyNow && $request->filled('variant') ? $request->integer('variant') : null,
            $isBuyNow ? array_map('intval', (array) $request->input('addons', [])) : [],
        );
    }
}
