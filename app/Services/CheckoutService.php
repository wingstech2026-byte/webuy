<?php

namespace App\Services;

use App\Enums\CheckoutStatus;
use App\Enums\DeliveryMethod;
use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Checkout;
use App\Models\Coupon;
use App\Models\GroupBuyParticipant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderMilestone;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Sms\SmsMessages;
use App\Support\Money;
use App\Support\RewardSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a cart (or a "Buy now" item) into one checkout and one order per seller.
 * Stock is taken off when the checkout is created and given back if the payment fails or times out.
 */
class CheckoutService
{
    public function __construct(
        private PaymentService $payments,
        private SmsService $sms,
        private CouponService $coupons,
        private FlashDealService $flashDeals,
        private FarmService $farms,
        private StockService $stock,
    ) {}

    /**
     * What the checkout page shows: items, delivery fee and total per seller, and the grand total.
     *
     * @param  Collection<int, array{product: Product, quantity: int}>  $lines  products with seller.deliveryAreas and tiers loaded
     * @param  array<int, string>  $methods  seller id => 'delivery' | 'pickup'
     * @param  ?User  $buyer  for WiBuy Pass member prices and free delivery
     * @param  ?Coupon  $coupon  a coupon the buyer chose; when it does not apply, coupon_error says why
     * @return array{groups: array<int, array<string, mixed>>, items_total: string, delivery_total: string, grand_total: string, discount_total: string, amount_due: string, coupon: ?Coupon, coupon_discount: string, coupon_error: ?string, pass_savings: string, is_member: bool}
     */
    public function summarize(Collection $lines, ?string $district, array $methods = [], ?User $buyer = null, ?Coupon $coupon = null, string $channel = 'web', ?array $point = null): array
    {
        $geo = app(GeoService::class);
        $lat = isset($point['lat']) && $geo->isValid($point['lat'], $point['lng'] ?? null) ? (float) $point['lat'] : null;
        $lng = $lat !== null ? (float) $point['lng'] : null;
        $groups = [];
        $isMember = (bool) $buyer?->hasPass();
        $isBusiness = (bool) $buyer?->isVerifiedBusiness();
        $freeDeliveryFrom = RewardSettings::money('pass_free_delivery_min');

        foreach ($lines->groupBy(fn ($line) => $line['product']->seller_id) as $sellerId => $sellerLines) {
            $seller = $sellerLines->first()['product']->seller;
            $items = $sellerLines->map(function ($line) use ($isMember, $isBusiness) {
                // A locked price (accepted quote, sample) wins; otherwise price tiers (business-only ones for
                // verified businesses), a live lightning deal and the member price: whichever is lowest.
                $unit = isset($line['unit_price'])
                    ? Money::from($line['unit_price'])
                    : $line['product']->unitPriceForBuyer($line['quantity'], $isMember, $isBusiness, $line['variant'] ?? null);

                return [
                    'product' => $line['product'],
                    'variant' => $line['variant'] ?? null,
                    'quantity' => $line['quantity'],
                    'is_sample' => (bool) ($line['is_sample'] ?? false),
                    'unit_price' => $unit,
                    'line_total' => Money::mul($unit, $line['quantity']),
                ];
            })->values();

            $subtotal = Money::sum($items->pluck('line_total'));
            // Delivery radius from the seller's locations when we know the point, else the district zones.
            $quote = $geo->deliveryQuote($seller, $district, $lat, $lng, $subtotal);
            $districtFee = $quote['can_deliver'] ? $quote['fee'] : null;
            $canDeliver = $districtFee !== null;
            $canPickup = (bool) $seller->offers_pickup;

            $method = isset($methods[$sellerId]) ? DeliveryMethod::tryFrom($methods[$sellerId]) : null;
            $method ??= $canDeliver ? DeliveryMethod::Delivery : ($canPickup ? DeliveryMethod::Pickup : null);

            $fee = $method === DeliveryMethod::Delivery && $canDeliver ? Money::from($districtFee) : '0.00';

            // WiBuy Pass: WiBuy pays the delivery fee of eligible shop orders (the seller still gets it).
            $passDiscount = $isMember && Money::gt($fee, 0) && Money::gte($subtotal, $freeDeliveryFrom) ? $fee : '0.00';

            $groups[$sellerId] = [
                'seller' => $seller,
                'items' => $items,
                'subtotal' => $subtotal,
                'method' => $method,
                'can_deliver' => $canDeliver,
                'can_pickup' => $canPickup,
                'district_fee' => $districtFee === null ? null : Money::from($districtFee),
                'delivery_reason' => $quote['reason'],
                'distance_km' => $quote['distance_km'],
                'delivery_window' => $quote['window'],
                'delivery_fee' => $fee,
                'total' => Money::add($subtotal, $fee),
                'pass_discount' => $passDiscount,
                'discount' => $passDiscount,
            ];
        }

        [$couponDiscount, $couponError] = $this->applyCoupon($groups, $buyer, $coupon, $channel);

        foreach ($groups as $sellerId => $group) {
            $groups[$sellerId]['amount_due'] = Money::sub($group['total'], $group['discount']);
        }

        $itemsTotal = Money::sum(array_column($groups, 'subtotal'));
        $deliveryTotal = Money::sum(array_column($groups, 'delivery_fee'));
        $grandTotal = Money::add($itemsTotal, $deliveryTotal);
        $discountTotal = Money::sum(array_column($groups, 'discount'));

        return [
            'groups' => $groups,
            'items_total' => $itemsTotal,
            'delivery_total' => $deliveryTotal,
            'grand_total' => $grandTotal,
            'discount_total' => $discountTotal,
            'amount_due' => Money::sub($grandTotal, $discountTotal),
            'coupon' => $couponError ? null : $coupon,
            'coupon_discount' => $couponDiscount,
            'coupon_error' => $couponError,
            'pass_savings' => Money::sum(array_column($groups, 'pass_discount')),
            'is_member' => $isMember,
        ];
    }

    /**
     * Spread the coupon over the shop orders: a product coupon goes to that product's shop, any other
     * coupon is split by order size. Never more than what is left to pay on an order.
     *
     * @return array{0: string, 1: ?string} the coupon discount and an error message
     */
    private function applyCoupon(array &$groups, ?User $buyer, ?Coupon $coupon, string $channel): array
    {
        if (! $coupon || ! $buyer || $groups === []) {
            return ['0.00', null];
        }

        try {
            $discount = $this->coupons->discountFor($coupon, $buyer, collect($groups)->flatMap(fn ($g) => $g['items']), $channel);
        } catch (MarketplaceException $e) {
            return ['0.00', $e->getMessage()];
        }

        $shares = [];

        if ($coupon->product_id) {
            foreach ($groups as $sellerId => $group) {
                if ($group['items']->contains(fn ($item) => $item['product']->id === $coupon->product_id)) {
                    $shares[$sellerId] = $discount;

                    break;
                }
            }
        } else {
            $itemsTotal = Money::sum(array_column($groups, 'subtotal'));
            $left = $discount;
            $ids = array_keys($groups);

            foreach ($ids as $i => $sellerId) {
                $share = $i === count($ids) - 1 ? $left
                    : Money::from(bcdiv(bcmul($discount, $groups[$sellerId]['subtotal'], 4), $itemsTotal, 2));
                $shares[$sellerId] = $share;
                $left = Money::sub($left, $share);
            }
        }

        $given = '0.00';

        foreach ($shares as $sellerId => $share) {
            $room = Money::sub($groups[$sellerId]['total'], $groups[$sellerId]['discount']);
            $share = Money::cmp($share, $room) > 0 ? $room : $share;
            $groups[$sellerId]['discount'] = Money::add($groups[$sellerId]['discount'], $share);
            $given = Money::add($given, $share);
        }

        return [$given, null];
    }

    /**
     * Create the checkout, its orders and the mobile money deposit. Products are locked in id order
     * so two buyers can never take the same last unit and stock can never go below zero.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $lines
     * @param  array{name: string, phone: string, district?: ?string, address?: ?string, note?: ?string}  $details
     * @param  array<int, string>  $methods  seller id => 'delivery' | 'pickup'
     * @param  ?int  $couponId  one of the buyer's coupons
     * @param  string  $channel  web | app (some coupons only work in the app)
     * @param  array{payment_plan?: string, rfq_quote_id?: int}  $options  milestones = pay in parts; rfq_quote_id links an accepted quote
     *
     * @throws MarketplaceException
     */
    public function place(User $buyer, array $lines, array $details, array $methods, Operator $operator, string $payerPhone, ?int $couponId = null, string $channel = 'web', array $options = []): Checkout
    {
        // One line per product and option (variant): "Shirt, Red XL" and "Shirt, Blue M" are separate lines.
        $wanted = collect($lines)
            ->groupBy(fn ($line) => $line['product_id'].':'.($line['variant_id'] ?? ''))
            ->map(fn ($rows) => [
                'product_id' => (int) $rows->first()['product_id'],
                'variant_id' => isset($rows->first()['variant_id']) ? (int) $rows->first()['variant_id'] : null,
                'quantity' => (int) $rows->sum('quantity'),
            ])->values();
        // Lines with a locked price (an accepted quote or a sample), keyed by product.
        $locked = collect($lines)->filter(fn ($line) => isset($line['unit_price']))->keyBy('product_id');
        $milestones = ($options['payment_plan'] ?? 'full') === 'milestones';

        if ($wanted->isEmpty()) {
            throw new MarketplaceException('Your cart is empty.');
        }

        return DB::transaction(function () use ($buyer, $wanted, $locked, $milestones, $options, $details, $methods, $operator, $payerPhone, $couponId, $channel) {
            $products = Product::whereIn('id', $wanted->pluck('product_id')->unique())->orderBy('id')->lockForUpdate()
                ->with(['seller.deliveryAreas', 'tiers', 'images', 'variants'])->get()->keyBy('id');
            $variants = ProductVariant::whereIn('id', $wanted->pluck('variant_id')->filter()->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            // The minimum order and the product's stock count every option of the product together.
            $perProduct = $wanted->groupBy('product_id')->map(fn ($rows) => (int) $rows->sum('quantity'));

            $lines = collect();

            foreach ($wanted as ['product_id' => $productId, 'variant_id' => $variantId, 'quantity' => $quantity]) {
                $product = $products->get($productId);

                if (! $product || ! $product->isListed()) {
                    throw new MarketplaceException('One of the items in your cart is no longer available.');
                }

                if ($product->seller->user_id === $buyer->id) {
                    throw new MarketplaceException('You cannot buy your own products.');
                }

                $isSample = (bool) ($locked[$productId]['is_sample'] ?? false);

                if ($isSample && $quantity !== 1) {
                    throw new MarketplaceException('A sample is one unit.');
                }

                $isGroup = (bool) ($locked[$productId]['is_group'] ?? false);

                if (! $isSample && ! $isGroup && $perProduct[$productId] < $product->min_order_quantity) {
                    throw new MarketplaceException("The minimum order for {$product->title} is {$product->min_order_quantity} {$product->unit}.");
                }

                if ($perProduct[$productId] > $product->stock) {
                    throw new MarketplaceException(
                        $product->stock > 0
                            ? "Only {$product->stock} left of {$product->title}."
                            : "{$product->title} is out of stock."
                    );
                }

                $hasOptions = $product->variants->contains('is_active', true);
                $variant = $variantId ? $variants->get($variantId) : null;

                if ($hasOptions && (! $variant || $variant->product_id !== $product->id || ! $variant->is_active)) {
                    throw new MarketplaceException("Choose an option for {$product->title} (size, colour...).");
                }

                if (! $hasOptions && $variantId) {
                    throw new MarketplaceException("{$product->title} has no options to choose from.");
                }

                if ($variant && $quantity > $variant->stock) {
                    throw new MarketplaceException($variant->stock > 0
                        ? "Only {$variant->stock} left of {$product->title} ({$variant->name})."
                        : "{$product->title} ({$variant->name}) is out of stock.");
                }

                $lines->push(array_filter([
                    'product' => $product,
                    'variant' => $variant,
                    'quantity' => $quantity,
                    'unit_price' => $locked[$productId]['unit_price'] ?? null,
                    'is_sample' => $isSample ?: null,
                ], fn ($value) => $value !== null));
            }

            $coupon = $couponId ? Coupon::where('user_id', $buyer->id)->lockForUpdate()->find($couponId) : null;

            if ($couponId && ! $coupon) {
                throw new MarketplaceException('This coupon cannot be used.');
            }

            $point = isset($details['latitude'], $details['longitude']) ? ['lat' => $details['latitude'], 'lng' => $details['longitude']] : null;
            $summary = $this->summarize($lines, $details['district'] ?? null, $methods, $buyer, $coupon, $channel, $point);

            if ($summary['coupon_error']) {
                throw new MarketplaceException($summary['coupon_error']);
            }

            $firstPayment = $summary['amount_due'];

            if ($milestones) {
                if (count($summary['groups']) !== 1) {
                    throw new MarketplaceException('Paying in parts works for an order from one shop. Check out each shop separately.');
                }

                if (Money::cmp($summary['amount_due'], RewardSettings::money('milestone_min_amount')) < 0) {
                    throw new MarketplaceException('Paying in parts is for orders of '.Money::format(RewardSettings::money('milestone_min_amount')).' or more.');
                }

                $firstPayment = Money::percentOf($summary['amount_due'], RewardSettings::int('milestone_deposit_percent'));
            }

            foreach ($summary['groups'] as $sellerId => $group) {
                $this->assertDeliverable($group, $methods[$sellerId] ?? null, $details);
            }

            $checkout = Checkout::create([
                'buyer_id' => $buyer->id,
                'total' => $firstPayment, // the whole amount, or the deposit when paying in parts
                'discount' => $summary['discount_total'],
                'payment_plan' => $milestones ? 'milestones' : 'full',
                'coupon_id' => $coupon?->id,
                'channel' => $channel,
                'status' => CheckoutStatus::PendingPayment,
                'payer_phone' => $payerPhone,
                'operator' => $operator,
                'expires_at' => now()->addMinutes(config('wibuy.payment_hold_minutes')),
            ]);

            foreach ($summary['groups'] as $group) {
                $seller = $group['seller'];
                $rate = $seller->commissionRate();
                $pickup = $group['method'] === DeliveryMethod::Pickup;

                $order = Order::create([
                    'checkout_id' => $checkout->id,
                    'buyer_id' => $buyer->id,
                    'seller_id' => $seller->id,
                    'status' => OrderStatus::PendingPayment,
                    'subtotal' => $group['subtotal'],
                    'delivery_fee' => $group['delivery_fee'],
                    'commission_percent' => $rate,
                    'commission' => Money::percentOf($group['subtotal'], $rate),
                    'total' => $group['total'],
                    'discount' => $group['discount'],
                    'rfq_quote_id' => $options['rfq_quote_id'] ?? null,
                    'delivery_method' => $group['method'],
                    'recipient_name' => $details['name'],
                    'recipient_phone' => $details['phone'],
                    'district' => $pickup ? null : ($details['district'] ?? null),
                    'address' => $pickup ? null : ($details['address'] ?? null),
                    'landmark' => $pickup ? null : ($details['landmark'] ?? null),
                    'saved_address_id' => $pickup ? null : ($details['saved_address_id'] ?? null),
                    'delivery_latitude' => $pickup || $point === null ? null : $point['lat'],
                    'delivery_longitude' => $pickup || $point === null ? null : $point['lng'],
                    'distance_km' => $pickup ? null : $group['distance_km'],
                    'buyer_note' => $details['note'] ?? null,
                    'stock_reserved' => true,
                    'expires_at' => $checkout->expires_at,
                ]);

                foreach ($group['items'] as $item) {
                    /** @var Product $product */
                    $product = $item['product'];

                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $product->id,
                        'variant_id' => $item['variant']?->id,
                        'title' => $product->title,
                        'variant_name' => $item['variant']?->name,
                        'unit' => $product->unit,
                        'unit_price' => $item['unit_price'],
                        'quantity' => $item['quantity'],
                        'line_total' => $item['line_total'],
                        'is_sample' => $item['is_sample'],
                        'thumb_path' => $product->cover?->thumb_path,
                    ]);

                    // Reserve the stock now (the rows are locked, so this cannot go below zero).
                    $this->stock->reserve([['product_id' => $product->id, 'variant_id' => $item['variant']?->id, 'quantity' => $item['quantity']]]);
                }

                if ($milestones) {
                    $percent = RewardSettings::int('milestone_deposit_percent');
                    OrderMilestone::create(['order_id' => $order->id, 'position' => 1, 'label' => "Deposit ({$percent}%)", 'percent' => $percent, 'amount' => $firstPayment]);
                    OrderMilestone::create(['order_id' => $order->id, 'position' => 2, 'label' => 'Balance before delivery', 'percent' => 100 - $percent, 'amount' => Money::sub($group['amount_due'], $firstPayment)]);
                }
            }

            if ($coupon) {
                $this->coupons->reserve($coupon, $checkout, $summary['coupon_discount']);
            }

            // Fully covered by coupons (e.g. a free trial picked up at the shop): nothing to pay by phone.
            if (Money::isZero($firstPayment)) {
                $this->settlePaid($checkout);
            } else {
                $this->payments->createDeposit($checkout, $operator, $payerPhone);
            }

            return $checkout->refresh()->load('orders.items');
        });
    }

    /** @throws MarketplaceException */
    private function assertDeliverable(array $group, ?string $method, array $details): void
    {
        $shop = $group['seller']->shop_name;
        $method = DeliveryMethod::tryFrom((string) $method);

        if (! $method) {
            throw new MarketplaceException("Choose delivery or pickup for {$shop}.");
        }

        if ($method === DeliveryMethod::Pickup) {
            if (! $group['can_pickup']) {
                throw new MarketplaceException("{$shop} does not offer pickup.");
            }

            return;
        }

        if (blank($details['district'] ?? null) || blank($details['address'] ?? null)) {
            throw new MarketplaceException('Please enter your district and delivery address.');
        }

        if (! $group['can_deliver']) {
            throw new MarketplaceException("{$shop} does not deliver to {$details['district']}. Choose pickup or another district.");
        }
    }

    // --------------------------------------------------------------- after payment

    /**
     * The deposit completed. Mark every order paid. Called with the checkout row locked.
     *
     * @return bool false when the checkout had already been cancelled and some items are gone,
     *              so the money must be refunded
     */
    public function settlePaid(Checkout $checkout): bool
    {
        return match ($checkout->status) {
            CheckoutStatus::Paid => true,
            CheckoutStatus::PendingPayment => $this->markPaid($checkout),
            CheckoutStatus::Cancelled => $this->revive($checkout),
        };
    }

    private function markPaid(Checkout $checkout): bool
    {
        $checkout->forceFill(['status' => CheckoutStatus::Paid, 'paid_at' => now()])->save();

        $orders = Order::where('checkout_id', $checkout->id)->with('items', 'seller.user')->get();

        $this->coupons->markUsed($checkout);

        // Paying in parts: the checkout's payment was the deposit.
        OrderMilestone::whereIn('order_id', $orders->pluck('id'))->where('position', 1)
            ->update(['status' => 'paid', 'paid_at' => now()]);

        foreach ($orders as $order) {
            $order->forceFill([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
                'expires_at' => null,
                'accept_by' => now()->addHours(config('wibuy.accept_within_hours')),
            ])->save();

            $this->sms->send($order->seller->user->phone, SmsMessages::orderPlaced($order));
            $this->flashDeals->recordSales($order);
        }

        if ($first = $orders->first()) {
            $this->farms->attachGifts($first);
        }

        // Group-buy orders wait for the group before the seller may accept them.
        app(GroupBuyService::class)->onCheckoutPaid($checkout);

        $this->farms->mission($checkout->buyer, 'order');

        $this->sms->send($checkout->buyer->phone, SmsMessages::paymentReceived($checkout));

        return true;
    }

    /** The buyer paid after the hold ran out. If every item can still be reserved, the order goes ahead. */
    private function revive(Checkout $checkout): bool
    {
        $orders = Order::where('checkout_id', $checkout->id)->with('items')->get();
        $needed = $this->stock->linesOf($orders->flatMap->items);

        $listed = Product::whereIn('id', $needed->pluck('product_id'))->with('seller')->get()->every(fn (Product $product) => $product->isListed());

        if (! $listed || ! $this->stock->available($needed)) {
            return false;
        }

        $this->stock->reserve($needed);

        $orders->each(fn (Order $order) => $order->forceFill(['status' => OrderStatus::PendingPayment, 'cancelled_at' => null, 'stock_reserved' => true])->save());

        // The buyer paid the discounted amount, so the coupon counts as used.
        if ($checkout->coupon_id) {
            Coupon::whereKey($checkout->coupon_id)->update(['status' => Coupon::USED, 'checkout_id' => $checkout->id, 'used_at' => now()]);
        }

        return $this->markPaid($checkout);
    }

    /** The payment failed or timed out: cancel the orders and give the stock back. */
    public function cancelUnpaid(Checkout $checkout): bool
    {
        return DB::transaction(function () use ($checkout) {
            $locked = Checkout::lockForUpdate()->find($checkout->id);

            if (! $locked || $locked->status !== CheckoutStatus::PendingPayment) {
                return false;
            }

            $locked->forceFill(['status' => CheckoutStatus::Cancelled])->save();
            $this->coupons->release($locked);
            GroupBuyParticipant::where('checkout_id', $locked->id)->where('status', 'pending')->update(['status' => 'cancelled']);

            $orders = Order::where('checkout_id', $locked->id)->with('items')->get();
            $this->stock->release($this->stock->linesOf($orders->where('stock_reserved', true)->flatMap->items));

            $orders->each(fn (Order $order) => $order->forceFill([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'expires_at' => null,
                'stock_reserved' => false,
            ])->save());

            return true;
        });
    }
}
