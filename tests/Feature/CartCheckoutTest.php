<?php

namespace Tests\Feature;

use App\Enums\CheckoutStatus;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\MarketplaceException;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class CartCheckoutTest extends TestCase
{
    use Marketplace;

    private function checkoutForm(array $methods, array $overrides = []): array
    {
        return array_merge([
            'source' => 'cart',
            'recipient_name' => 'Aminata Kamara',
            'recipient_phone' => '076 111 222',
            'district' => 'Western Area Urban',
            'address' => '12 Wilkinson Road',
            'methods' => $methods,
            'operator' => 'ORANGE',
            'payer_phone' => '076 111 222',
        ], $overrides);
    }

    // ------------------------------------------------------------------------- cart

    public function test_guests_are_asked_to_log_in_to_add_to_cart(): void
    {
        $product = $this->makeProduct();

        $this->post(route('cart.add', $product), ['quantity' => 1])->assertRedirect(route('login'));
    }

    public function test_adding_to_cart_and_changing_quantities(): void
    {
        $buyer = $this->buyer();
        $product = Product::factory()->withTiers()->create(['stock' => 100]);

        $this->actingAs($buyer)->post(route('cart.add', $product), ['quantity' => 2])->assertRedirect(route('cart.index'));
        $this->actingAs($buyer)->post(route('cart.add', $product), ['quantity' => 3]);

        $item = CartItem::sole();
        $this->assertSame(5, $item->quantity);

        $this->actingAs($buyer)->get(route('cart.index'))->assertOk()
            ->assertSee($product->title)->assertSee('Le 500.00');

        // 10 units reach the first wholesale tier: 90.00 each.
        $this->actingAs($buyer)->patch(route('cart.update', $item), ['quantity' => 10]);
        $this->actingAs($buyer)->get(route('cart.index'))->assertSee('Le 900.00')->assertSee('wholesale price');

        $this->actingAs($buyer)->get(route('home'))->assertSee('>10<', false); // header cart count

        $this->actingAs($buyer)->delete(route('cart.remove', $item))->assertRedirect();
        $this->assertSame(0, CartItem::count());
        $this->actingAs($buyer)->get(route('cart.index'))->assertSee('Your cart is empty');
    }

    public function test_cart_respects_stock_minimum_order_and_ownership(): void
    {
        $buyer = $this->buyer();
        $product = $this->makeProduct(null, ['stock' => 3, 'min_order_quantity' => 2]);

        $this->actingAs($buyer)->post(route('cart.add', $product), ['quantity' => 1])->assertSessionHasErrors('general');
        $this->actingAs($buyer)->post(route('cart.add', $product), ['quantity' => 4])->assertSessionHasErrors(['general' => "Only 3 left of {$product->title}."]);
        $this->actingAs($product->seller->user)->post(route('cart.add', $product), ['quantity' => 2])
            ->assertSessionHasErrors(['general' => 'You cannot buy your own products.']);

        $draft = $this->makeProduct(null, ['status' => 'draft']);
        $this->actingAs($buyer)->post(route('cart.add', $draft), ['quantity' => 1])->assertSessionHasErrors('general');

        $this->assertSame(0, CartItem::count());
    }

    public function test_you_cannot_change_someone_elses_cart(): void
    {
        $owner = $this->buyer();
        $this->actingAs($owner)->post(route('cart.add', $this->makeProduct()), ['quantity' => 1]);
        $item = CartItem::sole();

        $this->actingAs($this->buyer())->patch(route('cart.update', $item), ['quantity' => 9])->assertSessionHasErrors('general');
        $this->actingAs($this->buyer())->delete(route('cart.remove', $item))->assertSessionHasErrors('general');
        $this->assertSame(1, $item->fresh()->quantity);
    }

    // --------------------------------------------------------------------- checkout

    public function test_checkout_page_shows_items_delivery_fees_and_grand_total(): void
    {
        $buyer = $this->buyer();
        $seller = $this->makeSeller(['Western Area Urban' => '20.00', 'Bo' => '60.00']);
        $product = $this->makeProduct($seller, ['price' => '150.00']);
        $this->actingAs($buyer)->post(route('cart.add', $product), ['quantity' => 2]);

        $this->actingAs($buyer)->get(route('checkout.show'))->assertOk()
            ->assertSee('Le 300.00')   // items
            ->assertSee('Le 20.00')    // delivery to Western Area Urban (default district)
            ->assertSee('Le 320.00');  // grand total

        $this->actingAs($buyer)->get(route('checkout.show', ['district' => 'Bo']))
            ->assertSee('Le 60.00')->assertSee('Le 360.00');

        $this->actingAs($buyer)->get(route('checkout.show', ['district' => 'Kono']))
            ->assertSee('This seller does not deliver to Kono');
    }

    public function test_checking_out_the_cart_with_the_fake_gateway(): void
    {
        $buyer = $this->buyer();
        $seller = $this->makeSeller(['Western Area Urban' => '20.00']);
        $product = $this->makeProduct($seller, ['price' => '150.00', 'stock' => 10]);
        $this->actingAs($buyer)->post(route('cart.add', $product), ['quantity' => 2]);

        $response = $this->actingAs($buyer)->post(route('checkout.store'), $this->checkoutForm([$seller->id => 'delivery']));

        $checkout = Checkout::sole();
        $response->assertRedirect(route('checkout.status', $checkout));

        $this->assertSame(CheckoutStatus::PendingPayment, $checkout->status);
        $this->assertSame('320.00', $checkout->total);
        $this->assertSame(0, CartItem::count(), 'the cart is emptied');
        $this->assertSame(8, $product->fresh()->stock, 'stock is reserved straight away');

        $order = Order::sole();
        $this->assertSame(OrderStatus::PendingPayment, $order->status);
        $this->assertSame(DeliveryMethod::Delivery, $order->delivery_method);
        $this->assertSame(['300.00', '20.00', '15.00', '320.00'], [$order->subtotal, $order->delivery_fee, $order->commission, $order->total]);
        $this->assertSame('23276111222', $order->recipient_phone);
        $this->assertSame('12 Wilkinson Road', $order->address);
        $this->assertSame(2, $order->items->sole()->quantity);

        $deposit = $this->depositOf($checkout);
        $this->assertSame('320.00', $deposit->amount);
        $this->assertSame(PaymentStatus::Pending, $deposit->status);

        // The payment page asks to approve on the phone and refreshes itself.
        $this->actingAs($buyer)->get(route('checkout.status', $checkout))->assertOk()
            ->assertSee('Approve the payment prompt on your phone')
            ->assertSee('http-equiv="refresh"', false)
            ->assertSee('Simulate approve');

        // Approve on the "phone".
        $this->actingAs($buyer)->post(route('fake.approve', $deposit))->assertRedirect(route('checkout.status', $checkout));

        $this->assertSame(CheckoutStatus::Paid, $checkout->fresh()->status);
        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertEqualsWithDelta(24 * 3600, now()->diffInSeconds($order->accept_by, true), 5);

        // Texts: buyer "payment received", seller "new order".
        $this->assertStringContainsString('We got your payment of Le 320.00', $this->sms->lastTo($buyer->phone));
        $this->assertStringContainsString("New order {$order->number}", $this->sms->lastTo($seller->user->phone));
        $this->assertStringContainsString('Buy and sell easy for Salone.', $this->sms->lastTo($seller->user->phone));

        $this->actingAs($buyer)->get(route('checkout.status', $checkout))
            ->assertSee('Payment received')->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_a_multi_seller_cart_becomes_one_order_per_seller_and_one_payment(): void
    {
        $buyer = $this->buyer();
        $freetown = $this->makeSeller(['Western Area Urban' => '20.00'], ['commission_percent' => null]);
        $bo = $this->makeSeller(['Western Area Urban' => '80.00']);
        $rice = $this->makeProduct($freetown, ['price' => '650.00']);
        $oil = $this->makeProduct($freetown, ['price' => '135.00']);
        $phone = $this->makeProduct($bo, ['price' => '2450.00']);

        foreach ([[$rice, 2], [$oil, 1], [$phone, 1]] as [$p, $q]) {
            $this->actingAs($buyer)->post(route('cart.add', $p), ['quantity' => $q]);
        }

        $this->actingAs($buyer)->post(route('checkout.store'), $this->checkoutForm([
            $freetown->id => 'delivery',
            $bo->id => 'pickup',
        ]))->assertRedirect();

        $checkout = Checkout::sole();
        $orders = $checkout->orders()->with('items')->get()->keyBy('seller_id');
        $this->assertCount(2, $orders);

        $a = $orders[$freetown->id];
        $this->assertSame(['1435.00', '20.00', '1455.00'], [$a->subtotal, $a->delivery_fee, $a->total]);
        $this->assertCount(2, $a->items);
        $this->assertSame(DeliveryMethod::Delivery, $a->delivery_method);

        $b = $orders[$bo->id];
        $this->assertSame(['2450.00', '0.00', '2450.00'], [$b->subtotal, $b->delivery_fee, $b->total]);
        $this->assertSame(DeliveryMethod::Pickup, $b->delivery_method);
        $this->assertNull($b->address, 'pickup orders do not carry the delivery address');

        // One mobile money prompt for everything.
        $this->assertSame('3905.00', $checkout->total);
        $this->assertSame(1, $checkout->payments()->count());

        $this->pay($checkout);
        $this->assertSame([OrderStatus::Paid, OrderStatus::Paid], $checkout->orders()->get()->pluck('status')->all());
        $this->assertNotNull($this->sms->lastTo($freetown->user->phone));
        $this->assertNotNull($this->sms->lastTo($bo->user->phone));
    }

    public function test_wholesale_tier_prices_are_used_at_checkout(): void
    {
        $buyer = $this->buyer();
        $product = Product::factory()->withTiers([10 => '90.00', 50 => '80.00'])->create(['stock' => 100]);

        $checkout = $this->placeOrder($buyer, [[$product, 50]]);

        $item = $checkout->orders->first()->items->first();
        $this->assertSame('80.00', $item->unit_price);
        $this->assertSame('4000.00', $item->line_total);
    }

    public function test_commission_uses_the_admin_setting_or_the_sellers_own_rate(): void
    {
        Setting::put('commission_percent', '7.5');
        $buyer = $this->buyer();
        $normal = $this->makeProduct(null, ['price' => '199.99']);
        $special = $this->makeProduct($this->makeSeller([], ['commission_percent' => '2.00']), ['price' => '100.00']);

        $orders = $this->placeOrder($buyer, [[$normal, 1], [$special, 1]])->orders->keyBy('seller_id');

        $this->assertSame('15.00', $orders[$normal->seller_id]->commission); // 7.5% of 199.99 = 14.99925 -> 15.00
        $this->assertSame('7.50', $orders[$normal->seller_id]->commission_percent);
        $this->assertSame('2.00', $orders[$special->seller_id]->commission);
    }

    public function test_checkout_is_validated(): void
    {
        $buyer = $this->buyer();
        $seller = $this->makeSeller(['Western Area Urban' => '20.00'], ['offers_pickup' => false]);
        $product = $this->makeProduct($seller);
        $this->actingAs($buyer)->post(route('cart.add', $product), ['quantity' => 1]);

        $this->actingAs($buyer)->post(route('checkout.store'), [])
            ->assertSessionHasErrors(['source', 'recipient_name', 'recipient_phone', 'methods', 'operator', 'payer_phone']);
        $this->actingAs($buyer)->post(route('checkout.store'), $this->checkoutForm([$seller->id => 'delivery'], ['payer_phone' => '0761']))->assertSessionHasErrors('payer_phone');
        $this->actingAs($buyer)->post(route('checkout.store'), $this->checkoutForm([$seller->id => 'delivery'], ['operator' => 'MTN']))->assertSessionHasErrors('operator');
        $this->actingAs($buyer)->post(route('checkout.store'), $this->checkoutForm([$seller->id => 'pickup']))
            ->assertSessionHasErrors(['general' => "{$seller->shop_name} does not offer pickup."]);
        $this->actingAs($buyer)->post(route('checkout.store'), $this->checkoutForm([$seller->id => 'delivery'], ['district' => 'Kono']))
            ->assertSessionHasErrors('general');
        $this->actingAs($buyer)->post(route('checkout.store'), $this->checkoutForm([$seller->id => 'delivery'], ['address' => '']))
            ->assertSessionHasErrors(['general' => 'Please enter your district and delivery address.']);

        $this->assertSame(0, Checkout::count());
        $this->assertSame(1, CartItem::count(), 'the cart is kept when checkout fails');
    }

    public function test_buy_now_checks_out_one_product_without_touching_the_cart(): void
    {
        $buyer = $this->buyer();
        $other = $this->makeProduct();
        $this->actingAs($buyer)->post(route('cart.add', $other), ['quantity' => 1]);
        $product = $this->makeProduct(null, ['price' => '50.00', 'stock' => 9]);

        $this->actingAs($buyer)->post(route('cart.add', $product), ['quantity' => 3, 'buy_now' => '1'])
            ->assertRedirect(route('checkout.show', ['product' => $product->slug, 'qty' => 3]));

        $this->actingAs($buyer)->get(route('checkout.show', ['product' => $product->slug, 'qty' => 3]))
            ->assertOk()->assertSee('Le 150.00')->assertDontSee($other->title);

        $this->actingAs($buyer)->post(route('checkout.store'), $this->checkoutForm(
            [$product->seller_id => 'pickup'], ['source' => 'buy', 'product' => $product->slug, 'qty' => 3]
        ))->assertRedirect();

        $this->assertSame(3, Order::sole()->items->sole()->quantity);
        $this->assertSame(1, CartItem::count(), 'the cart still has the other product');
        $this->assertSame(6, $product->fresh()->stock);
    }

    public function test_the_payment_page_is_only_for_the_buyer(): void
    {
        $checkout = $this->placeOrder($this->buyer(), [[$this->makeProduct(), 1]]);

        $this->actingAs($this->buyer())->get(route('checkout.status', $checkout))->assertForbidden();
        $this->actingAs($this->buyer())->post(route('fake.approve', $this->depositOf($checkout)))->assertForbidden();
    }

    // --------------------------------------------------------- stock reservation

    public function test_stock_never_goes_below_zero(): void
    {
        $product = $this->makeProduct(null, ['stock' => 3]);

        $this->placeOrder($this->buyer(), [[$product, 2]]);
        $this->assertSame(1, $product->fresh()->stock);

        try {
            $this->placeOrder($this->buyer(), [[$product, 2]]);
            $this->fail('Expected the second checkout to be refused');
        } catch (MarketplaceException $e) {
            $this->assertSame("Only 1 left of {$product->title}.", $e->getMessage());
        }

        $this->placeOrder($this->buyer(), [[$product, 1]]);
        $this->assertSame(0, $product->fresh()->stock);

        $this->expectException(MarketplaceException::class);
        $this->placeOrder($this->buyer(), [[$product, 1]]);
    }

    public function test_a_declined_payment_releases_the_stock(): void
    {
        $product = $this->makeProduct(null, ['stock' => 5]);
        $buyer = $this->buyer();
        $checkout = $this->placeOrder($buyer, [[$product, 4]]);
        $this->assertSame(1, $product->fresh()->stock);

        $this->actingAs($buyer)->post(route('fake.decline', $this->depositOf($checkout)));

        $this->assertSame(CheckoutStatus::Cancelled, $checkout->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, Order::sole()->status);
        $this->assertFalse(Order::sole()->stock_reserved);
        $this->assertSame(5, $product->fresh()->stock);

        $this->actingAs($buyer)->get(route('checkout.status', $checkout))->assertSee('The payment did not go through');
    }

    public function test_an_unpaid_checkout_is_cancelled_after_15_minutes_and_releases_stock(): void
    {
        $product = $this->makeProduct(null, ['stock' => 5]);
        $checkout = $this->placeOrder($this->buyer(), [[$product, 5]]);
        $this->assertSame(0, $product->fresh()->stock);

        $this->travel(14)->minutes();
        Artisan::call('wibuy:cancel-unpaid');
        $this->assertSame(CheckoutStatus::PendingPayment, $checkout->fresh()->status);

        $this->travel(2)->minutes();
        Artisan::call('wibuy:cancel-unpaid');

        $this->assertSame(CheckoutStatus::Cancelled, $checkout->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);

        // Running it again does not give the stock back twice.
        Artisan::call('wibuy:cancel-unpaid');
        app(CheckoutService::class)->cancelUnpaid($checkout);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_the_cancel_job_checks_the_gateway_first_so_a_last_second_payment_is_kept(): void
    {
        $product = $this->makeProduct(null, ['stock' => 5]);
        $checkout = $this->placeOrder($this->buyer(), [[$product, 2]]);
        $this->gatewayReports($this->depositOf($checkout)); // approved, webhook not received yet

        $this->travel(20)->minutes();
        Artisan::call('wibuy:cancel-unpaid');

        $this->assertSame(CheckoutStatus::Paid, $checkout->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock);
    }

    public function test_a_late_payment_revives_the_order_if_stock_is_still_there(): void
    {
        $product = $this->makeProduct(null, ['stock' => 5]);
        $checkout = $this->placeOrder($this->buyer(), [[$product, 2]]);

        $this->travel(16)->minutes();
        Artisan::call('wibuy:cancel-unpaid'); // gateway says pending, so it is cancelled
        $this->assertSame(5, $product->fresh()->stock);

        $this->pay($checkout); // the money arrives anyway

        $this->assertSame(CheckoutStatus::Paid, $checkout->fresh()->status);
        $this->assertSame(OrderStatus::Paid, Order::sole()->status);
        $this->assertSame(3, $product->fresh()->stock, 'stock is reserved again');
    }

    public function test_a_late_payment_is_refunded_if_the_stock_was_sold_meanwhile(): void
    {
        $product = $this->makeProduct(null, ['stock' => 2]);
        $late = $this->placeOrder($this->buyer(), [[$product, 2]]);

        $this->travel(16)->minutes();
        Artisan::call('wibuy:cancel-unpaid');
        $this->pay($this->placeOrder($this->buyer(), [[$product, 2]])); // someone else buys the last 2

        $this->pay($late);

        $order = $late->orders()->sole();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertSame(0, $product->fresh()->stock, 'never oversold');
        $refund = $order->payments()->where('kind', 'refund')->sole();
        $this->assertSame(PaymentStatus::Completed, $refund->status);
        $this->assertSame($order->total, $refund->amount);
    }

    public function test_the_buyer_can_cancel_an_unpaid_checkout(): void
    {
        $buyer = $this->buyer();
        $product = $this->makeProduct(null, ['stock' => 5]);
        $checkout = $this->placeOrder($buyer, [[$product, 5]]);

        $this->actingAs($buyer)->post(route('checkout.cancel', $checkout))->assertRedirect(route('cart.index'));

        $this->assertSame(CheckoutStatus::Cancelled, $checkout->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_the_payment_page_rechecks_the_gateway_if_no_webhook_came(): void
    {
        $buyer = $this->buyer();
        $checkout = $this->placeOrder($buyer, [[$this->makeProduct(), 1]]);
        $this->gatewayReports($this->depositOf($checkout));

        $this->travel(40)->seconds();
        $this->actingAs($buyer)->get(route('checkout.status', $checkout))->assertSee('Payment received');
    }

    public function test_checkout_is_rate_limited(): void
    {
        $buyer = $this->buyer();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($buyer)->post(route('checkout.store'), []);
        }

        $this->actingAs($buyer)->post(route('checkout.store'), [])->assertStatus(429);
    }

    public function test_payment_api_failures_never_leave_reserved_stock_stuck(): void
    {
        $product = $this->makeProduct(null, ['stock' => 5]);
        $checkout = $this->placeOrder($this->buyer(), [[$product, 5]]);

        // After its last retry, the payment job fails the deposit, which cancels the checkout.
        app(PaymentService::class)->markFailed($this->depositOf($checkout), 'Could not reach the payment provider.');

        $this->assertSame(CheckoutStatus::Cancelled, $checkout->fresh()->status);
        $this->assertSame(5, $product->fresh()->stock);
    }
}
