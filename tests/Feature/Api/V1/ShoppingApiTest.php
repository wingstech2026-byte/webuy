<?php

namespace Tests\Feature\Api\V1;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\Seller;
use App\Models\WishlistItem;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Browsing, cart, checkout with the fake gateway, payment polling and orders through the mobile API. */
class ShoppingApiTest extends TestCase
{
    use Marketplace;

    public function test_browsing_needs_no_login_and_money_is_sent_as_exact_strings(): void
    {
        $seller = $this->makeSeller([], ['shop_name' => 'Kissy Wholesale', 'is_verified' => true]);
        $rice = Product::factory()->withTiers([10 => '620.00'])->create([
            'seller_id' => $seller->id, 'title' => 'Local rice 50kg', 'price' => '650.00', 'is_deal' => true,
        ]);
        $this->makeProduct($seller, ['title' => 'Draft thing', 'status' => 'draft']);

        $this->getJson('/api/v1/meta')->assertOk()
            ->assertJsonPath('tagline', 'Buy and sell easy for Salone.')
            ->assertJsonPath('operators.0.value', 'ORANGE')
            ->assertJsonFragment(['Western Area Urban']);

        $this->getJson('/api/v1/home')->assertOk()->assertJsonPath('deals.0.title', 'Local rice 50kg');

        $this->getJson('/api/v1/products?q=rice')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.price', '650.00')
            ->assertJsonPath('data.0.tiers.0', ['min_quantity' => 10, 'unit_price' => '620.00'])
            ->assertJsonPath('data.0.seller.shop_name', 'Kissy Wholesale')
            ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/products/'.$rice->slug)->assertOk()
            ->assertJsonPath('data.title', 'Local rice 50kg')
            ->assertJsonPath('data.seller.is_verified', true)
            ->assertJsonStructure(['data' => ['description', 'images', 'web_url'], 'reviews']);

        $this->getJson('/api/v1/shops/'.$seller->slug)->assertOk()->assertJsonPath('data.shop_name', 'Kissy Wholesale');
    }

    public function test_hidden_products_and_shops_are_404(): void
    {
        $draft = $this->makeProduct(null, ['status' => 'draft']);
        $pending = Seller::factory()->pending()->create();

        $this->getJson('/api/v1/products/'.$draft->slug)->assertNotFound();
        $this->getJson('/api/v1/shops/'.$pending->slug)->assertNotFound();
    }

    public function test_the_cart_groups_by_seller_and_reports_rule_errors_as_422(): void
    {
        Sanctum::actingAs($buyer = $this->buyer());
        $product = Product::factory()->withTiers([10 => '90.00'])->create(['stock' => 20]);

        $this->postJson("/api/v1/cart/{$product->slug}", ['quantity' => 10])->assertCreated()
            ->assertJsonPath('count', 10)
            ->assertJsonPath('total', '900.00')
            ->assertJsonPath('groups.0.items.0.unit_price', '90.00')
            ->assertJsonPath('groups.0.items.0.is_wholesale_price', true);

        $this->postJson("/api/v1/cart/{$product->slug}", ['quantity' => 50])
            ->assertUnprocessable()->assertJson(['message' => "Only 20 left of {$product->title}."]);

        $item = CartItem::sole();
        $this->patchJson("/api/v1/cart/items/{$item->id}", ['quantity' => 3])->assertOk()->assertJsonPath('total', '300.00');
        $this->deleteJson("/api/v1/cart/items/{$item->id}")->assertOk()->assertJsonPath('count', 0);
    }

    public function test_quote_then_checkout_then_poll_then_approve(): void
    {
        Sanctum::actingAs($buyer = $this->buyer());
        $seller = $this->makeSeller(['Western Area Urban' => '20.00']);
        $product = $this->makeProduct($seller, ['price' => '150.00', 'stock' => 10]);
        $this->postJson("/api/v1/cart/{$product->slug}", ['quantity' => 2]);

        $this->postJson('/api/v1/checkout/quote', ['district' => 'Western Area Urban'])->assertOk()
            ->assertJsonPath('groups.0.method', 'delivery')
            ->assertJsonPath('groups.0.delivery_fee', '20.00')
            ->assertJsonPath('grand_total', '320.00');

        $this->postJson('/api/v1/checkout/quote', ['methods' => [$seller->id => 'pickup']])
            ->assertJsonPath('grand_total', '300.00');

        $checkoutId = $this->postJson('/api/v1/checkout', [
            'source' => 'cart', 'recipient_name' => 'Aminata', 'recipient_phone' => '076 111 222',
            'district' => 'Western Area Urban', 'address' => '12 Wilkinson Road',
            'methods' => [$seller->id => 'delivery'], 'operator' => 'ORANGE', 'payer_phone' => '076 111 222',
        ])->assertCreated()
            ->assertJsonPath('data.payment', 'waiting')
            ->assertJsonPath('data.total', '320.00')
            ->assertJsonPath('data.can_simulate', true)
            ->json('data.id');

        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(0, CartItem::count());

        $this->getJson("/api/v1/checkouts/{$checkoutId}")->assertOk()->assertJsonPath('data.payment', 'waiting');

        $this->postJson("/api/v1/checkouts/{$checkoutId}/simulate", ['outcome' => 'approve'])->assertOk()
            ->assertJsonPath('data.payment', 'paid')
            ->assertJsonPath('data.orders.0.status', 'paid');

        $this->assertSame(CheckoutStatus::Paid, Checkout::sole()->status);
    }

    public function test_a_declined_payment_shows_failed_and_releases_stock(): void
    {
        Sanctum::actingAs($buyer = $this->buyer());
        $product = $this->makeProduct(null, ['stock' => 5]);
        $checkout = $this->placeOrder($buyer, [[$product, 5]]);

        $this->postJson("/api/v1/checkouts/{$checkout->id}/simulate", ['outcome' => 'decline'])->assertOk()
            ->assertJsonPath('data.payment', 'failed');

        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_checkout_rule_errors_come_back_as_readable_422(): void
    {
        Sanctum::actingAs($this->buyer());

        $this->postJson('/api/v1/checkout/quote', [])->assertUnprocessable()->assertJson(['message' => 'Your cart is empty.']);
        $this->postJson('/api/v1/checkout', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_name', 'recipient_phone', 'methods', 'operator', 'payer_phone']);
    }

    public function test_simulating_a_payment_is_refused_unless_the_fake_gateway_is_on(): void
    {
        Sanctum::actingAs($buyer = $this->buyer());
        $checkout = $this->placeOrder($buyer, [[$this->makeProduct(), 1]]);
        config(['payments.gateway' => 'pawapay']);

        $this->postJson("/api/v1/checkouts/{$checkout->id}/simulate", ['outcome' => 'approve'])->assertNotFound();
    }

    public function test_orders_list_detail_and_confirm_delivery(): void
    {
        $order = $this->shippedOrder();
        Sanctum::actingAs($order->buyer);

        $this->getJson('/api/v1/orders')->assertOk()->assertJsonPath('data.0.number', $order->number);

        $this->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.can_confirm_delivery', true)
            ->assertJsonPath('data.timeline.0.label', 'Ordered and paid');

        $this->postJson("/api/v1/orders/{$order->id}/confirm")->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.can_review', true);

        $this->assertSame('95.00', LedgerEntry::sole()->amount);

        $item = $order->items()->first();
        $this->postJson("/api/v1/orders/{$order->id}/review", ['seller_rating' => 5, 'items' => [$item->id => ['rating' => 4]]])
            ->assertOk()->assertJsonPath('data.can_review', false);
    }

    public function test_other_peoples_orders_and_checkouts_are_404(): void
    {
        $order = $this->shippedOrder();
        Sanctum::actingAs($this->buyer());

        $this->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/confirm")->assertNotFound();
        $this->getJson("/api/v1/checkouts/{$order->checkout_id}")->assertNotFound();
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
    }

    public function test_confirming_too_early_is_a_readable_422(): void
    {
        $order = $this->paidOrder();
        Sanctum::actingAs($order->buyer);

        $this->postJson("/api/v1/orders/{$order->id}/confirm")
            ->assertUnprocessable()->assertJsonStructure(['message']);
    }

    public function test_the_wishlist_toggles(): void
    {
        Sanctum::actingAs($this->buyer());
        $product = $this->makeProduct(null, ['title' => 'Solar lamp']);

        $this->postJson("/api/v1/wishlist/{$product->slug}")->assertOk()->assertJson(['saved' => true]);
        $this->getJson('/api/v1/wishlist')->assertOk()->assertJsonPath('data.0.title', 'Solar lamp');
        $this->postJson("/api/v1/wishlist/{$product->slug}")->assertOk()->assertJson(['saved' => false]);
        $this->assertSame(0, WishlistItem::count());
    }
}
