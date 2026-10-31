<?php

namespace Tests\Feature;

use App\Enums\Operator;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Services\CheckoutService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Options (size, colour...) with their own stock and price, through cart, checkout and cancellation. */
class ProductVariantTest extends TestCase
{
    use Marketplace;

    private Seller $seller;

    private Product $shirt;

    private ProductVariant $red;

    private ProductVariant $blue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = $this->makeSeller();
        $this->shirt = $this->makeProduct($this->seller, ['title' => 'Shirt', 'price' => '100.00', 'stock' => 5]);
        $this->red = ProductVariant::factory()->create(['product_id' => $this->shirt->id, 'name' => 'Red, XL', 'stock' => 2]);
        $this->blue = ProductVariant::factory()->create(['product_id' => $this->shirt->id, 'name' => 'Blue, M', 'stock' => 3, 'price' => '120.00']);
    }

    public function test_a_seller_saves_options_and_the_product_stock_becomes_their_total(): void
    {
        $form = [
            'title' => 'Sandals', 'price' => '80.00', 'stock' => 999, 'unit' => 'pair', 'min_order_quantity' => 1,
            'condition' => 'new', 'status' => 'active',
            'variants' => [['name' => 'Size 40', 'stock' => 4], ['name' => 'Size 42', 'stock' => 6, 'price' => '90.00'], ['name' => '', 'stock' => '']],
        ];

        $this->actingAs($this->seller->user)->post(route('seller.products.store'), $form)->assertRedirect();

        $sandals = Product::where('title', 'Sandals')->sole();
        $this->assertSame(['Size 40', 'Size 42'], $sandals->variants()->pluck('name')->all());
        $this->assertSame(10, $sandals->stock);

        // Rename one, drop the other: never ordered, so it is deleted.
        $first = $sandals->variants()->first();
        $this->actingAs($this->seller->user)->put(route('seller.products.update', $sandals), ['variants' => [['id' => $first->id, 'name' => 'Size 41', 'stock' => 7]]] + $form)->assertRedirect();
        $this->assertSame(['Size 41'], $sandals->variants()->pluck('name')->all());
        $this->assertSame(7, $sandals->fresh()->stock);

        $this->actingAs($this->seller->user)->post(route('seller.products.store'), ['title' => 'Hats', 'variants' => [['name' => 'Red', 'stock' => 1], ['name' => 'red', 'stock' => 1]]] + $form)
            ->assertSessionHasErrors('variants.1.name');
    }

    public function test_an_option_already_ordered_is_switched_off_not_deleted(): void
    {
        Sanctum::actingAs($buyer = $this->buyer());
        $this->postJson("/api/v1/cart/{$this->shirt->slug}", ['quantity' => 1, 'variant' => $this->red->id])->assertCreated();
        $this->placeFromCart($buyer);

        $form = ['title' => 'Shirt', 'price' => '100.00', 'stock' => 1, 'unit' => 'piece', 'min_order_quantity' => 1, 'condition' => 'new', 'status' => 'active'];
        $this->actingAs($this->seller->user)->put(route('seller.products.update', $this->shirt), ['variants' => [['id' => $this->blue->id, 'name' => 'Blue, M', 'stock' => 3, 'price' => '120.00']]] + $form);

        $this->assertFalse($this->red->fresh()->is_active);
        $this->assertSame('Red, XL', OrderItem::sole()->variant_name);
    }

    public function test_the_cart_needs_an_option_keeps_options_apart_and_uses_their_price_and_stock(): void
    {
        Sanctum::actingAs($this->buyer());

        $this->postJson("/api/v1/cart/{$this->shirt->slug}", ['quantity' => 1])->assertUnprocessable()
            ->assertJson(['message' => 'Choose an option for Shirt (size, colour...).']);
        $this->postJson("/api/v1/cart/{$this->shirt->slug}", ['quantity' => 3, 'variant' => $this->red->id])->assertUnprocessable()
            ->assertJson(['message' => 'Only 2 left of Shirt (Red, XL).']);

        $this->postJson("/api/v1/cart/{$this->shirt->slug}", ['quantity' => 2, 'variant' => $this->red->id])->assertCreated();
        $this->postJson("/api/v1/cart/{$this->shirt->slug}", ['quantity' => 1, 'variant' => $this->blue->id])->assertCreated()
            ->assertJsonPath('total', '320.00')
            ->assertJsonPath('groups.0.items.1.variant.name', 'Blue, M')
            ->assertJsonPath('groups.0.items.1.unit_price', '120.00');

        $this->assertSame(2, CartItem::count());
        $this->getJson("/api/v1/products/{$this->shirt->slug}")->assertJsonCount(2, 'data.variants');
    }

    public function test_checkout_reserves_option_stock_and_a_cancelled_payment_gives_it_back(): void
    {
        Sanctum::actingAs($buyer = $this->buyer());
        $this->postJson("/api/v1/cart/{$this->shirt->slug}", ['quantity' => 2, 'variant' => $this->red->id]);
        $this->postJson("/api/v1/cart/{$this->shirt->slug}", ['quantity' => 1, 'variant' => $this->blue->id]);

        $checkout = $this->placeFromCart($buyer);

        $this->assertSame([0, 2, 2], [$this->red->fresh()->stock, $this->blue->fresh()->stock, $this->shirt->fresh()->stock]);
        $this->assertSame('320.00', $checkout->orders()->sole()->subtotal);
        $this->assertEqualsCanonicalizing(['Red, XL', 'Blue, M'], OrderItem::pluck('variant_name')->all());

        app(CheckoutService::class)->cancelUnpaid($checkout);

        $this->assertSame([2, 3, 5], [$this->red->fresh()->stock, $this->blue->fresh()->stock, $this->shirt->fresh()->stock]);
    }

    public function test_buy_now_on_the_website_asks_for_an_option(): void
    {
        $this->actingAs($this->buyer())->post(route('cart.add', $this->shirt), ['quantity' => 1, 'buy_now' => 1])
            ->assertSessionHasErrors('general');
        $this->actingAs($this->buyer())->post(route('cart.add', $this->shirt), ['quantity' => 1, 'buy_now' => 1, 'variant' => $this->blue->id])
            ->assertRedirect(route('checkout.show', ['product' => $this->shirt->slug, 'qty' => 1, 'variant' => $this->blue->id]));

        $this->get(route('products.show', $this->shirt))->assertSee('Blue, M')->assertSee('Choose an option');
    }

    private function placeFromCart($buyer): Checkout
    {
        $lines = CartItem::all()->map(fn (CartItem $i) => ['product_id' => $i->product_id, 'variant_id' => $i->variant_id, 'quantity' => $i->quantity])->all();

        return app(CheckoutService::class)->place(
            $buyer, $lines, ['name' => $buyer->name, 'phone' => $buyer->phone, 'district' => 'Western Area Urban', 'address' => '1 Road'],
            [$this->seller->id => 'pickup'], Operator::Orange, $buyer->phone,
        );
    }
}
