<?php

namespace Tests\Concerns;

use App\Enums\Operator;
use App\Enums\PaymentKind;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\PaymentService;

trait Marketplace
{
    /** An approved shop that delivers to the given districts (district => fee). */
    protected function makeSeller(array $areas = ['Western Area Urban' => '20.00'], array $attributes = []): Seller
    {
        return Seller::factory()->delivering($areas)->create($attributes)->load('user');
    }

    protected function makeProduct(?Seller $seller = null, array $attributes = []): Product
    {
        return Product::factory()->create(['seller_id' => ($seller ?? $this->makeSeller())->id] + $attributes);
    }

    protected function buyer(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /**
     * Check out through the real service.
     *
     * @param  array<int, array{0: Product, 1: int}>  $lines
     */
    protected function placeOrder(User $buyer, array $lines, string $method = 'pickup', string $district = 'Western Area Urban'): Checkout
    {
        $methods = [];

        foreach ($lines as [$product]) {
            $methods[$product->seller_id] = $method;
        }

        return app(CheckoutService::class)->place(
            $buyer,
            array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
            ['name' => $buyer->name, 'phone' => $buyer->phone, 'district' => $district, 'address' => '12 Test Street'],
            $methods,
            Operator::Orange,
            $buyer->phone,
        );
    }

    protected function depositOf(Checkout $checkout): Payment
    {
        return $checkout->payments()->where('kind', PaymentKind::Deposit)->latest('id')->firstOrFail();
    }

    /** Make the fake gateway report this payment's outcome (what the phone answered). */
    protected function gatewayReports(Payment $payment, string $status = 'COMPLETED'): void
    {
        $payment->forceFill([
            'raw_response' => array_merge($payment->raw_response ?? [], ['fake_status' => $status]),
        ])->save();
    }

    /** The buyer approved the prompt and the gateway confirmed it. */
    protected function pay(Checkout $checkout): Checkout
    {
        $deposit = $this->depositOf($checkout);
        $this->gatewayReports($deposit);
        app(PaymentService::class)->sync($deposit);

        return $checkout->refresh();
    }

    /** A paid order for one product (quantity 1 unless given). */
    protected function paidOrder(?Product $product = null, ?User $buyer = null, int $quantity = 1, string $method = 'pickup'): Order
    {
        $product ??= $this->makeProduct();
        $buyer ??= $this->buyer();

        $checkout = $this->pay($this->placeOrder($buyer, [[$product, $quantity]], $method));

        return $checkout->orders()->firstOrFail();
    }

    /** A paid order taken all the way to "shipped" by its seller. */
    protected function shippedOrder(?Product $product = null, ?User $buyer = null): Order
    {
        $order = $this->paidOrder($product, $buyer);
        $this->orders()->accept($order);

        return $this->orders()->ship($order, 'On its way');
    }

    protected function orders(): OrderService
    {
        return app(OrderService::class);
    }
}
