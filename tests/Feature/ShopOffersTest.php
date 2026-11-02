<?php

namespace Tests\Feature;

use App\Enums\LedgerType;
use App\Enums\Operator;
use App\Exceptions\MarketplaceException;
use App\Models\Bundle;
use App\Models\CartItem;
use App\Models\Checkout;
use App\Models\DeliveryJob;
use App\Models\LedgerEntry;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ShopOffer;
use App\Models\User;
use App\Services\CartService;
use App\Services\CheckoutService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Shop offers and bundles (paid by the shop), tips for the rider, and extras on dishes. */
class ShopOffersTest extends TestCase
{
    use Marketplace;

    /** @param array<int, array{0: Product, 1: int}> $lines */
    private function checkout(User $buyer, array $lines, array $options = [], string $method = 'pickup'): Checkout
    {
        return app(CheckoutService::class)->place(
            $buyer,
            array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1], 'addon_ids' => $l[2] ?? []], $lines),
            ['name' => $buyer->name, 'phone' => $buyer->phone, 'district' => 'Western Area Urban', 'address' => '12 Test Street'],
            [$lines[0][0]->seller_id => $method],
            Operator::Orange,
            $buyer->phone,
            null,
            'web',
            $options,
        );
    }

    public function test_a_spend_offer_and_a_code_are_paid_by_the_shop_and_a_code_works_once_per_buyer(): void
    {
        $product = $this->makeProduct(null, ['price' => '100.00']);
        $seller = $product->seller;
        $this->actingAs($seller->user)->post(route('seller.offers.store'), ['kind' => 'spend', 'amount' => 30, 'min_spend' => 300])->assertSessionHas('status');
        $this->actingAs($seller->user)->post(route('seller.offers.store'), ['kind' => 'code', 'code' => 'friday10', 'percent' => 10])->assertSessionHas('status');
        $buyer = $this->buyer();

        // Le 400: the automatic Le 30 beats the code's Le 40? No: the code saves more, so the code is used.
        $order = $this->pay($this->checkout($buyer, [[$product, 4]], ['promo_code' => 'FRIDAY10']))->orders()->sole();
        $this->assertSame(['40.00', '360.00'], [$order->shop_discount, $order->total]);
        $this->assertSame('18.00', $order->commission); // 5% of what the buyer pays for the goods (360), not of 400
        $this->assertSame(1, ShopOffer::where('code', 'FRIDAY10')->value('uses'));

        // Again: the code is used up for this buyer; without it the spend offer still applies.
        $this->expectExceptionMessage('You already used this code.');
        try {
            $this->checkout($buyer, [[$product, 4]], ['promo_code' => 'friday10']);
        } finally {
            $this->assertSame('30.00', $this->pay($this->checkout($buyer, [[$product, 4]]))->orders()->sole()->shop_discount);
        }
    }

    public function test_buy_two_get_one_free_and_a_bundle_lower_the_price(): void
    {
        $seller = $this->makeSeller();
        $rice = $this->makeProduct($seller, ['title' => 'Rice', 'price' => '100.00']);
        $oil = $this->makeProduct($seller, ['title' => 'Oil', 'price' => '50.00']);
        $this->actingAs($seller->user)->post(route('seller.offers.store'), ['kind' => 'bogo', 'product' => $oil->id, 'buy_quantity' => 2])->assertSessionHas('status');
        $this->actingAs($seller->user)->post(route('seller.bundles.store'), ['title' => 'Cooking pack', 'price' => 130, 'items' => [['product' => $rice->id, 'quantity' => 1], ['product' => $oil->id, 'quantity' => 1]]])
            ->assertSessionHas('status');
        $this->actingAs($seller->user)->post(route('seller.bundles.store'), ['title' => 'Too dear', 'price' => 200, 'items' => [['product' => $rice->id], ['product' => $oil->id]]])
            ->assertSessionHasErrors('bundle');

        $buyer = $this->buyer();
        $this->get(route('products.show', $oil))->assertSee('Buy 2 Oil, get 1 free')->assertSee('Cooking pack');
        $this->actingAs($buyer)->post(route('bundles.cart', Bundle::sole()))->assertRedirect(route('cart.index'));
        $this->assertSame(2, CartItem::count());

        // 1 rice + 3 oil = Le 250; the bundle saves 20, every third oil is free (50).
        $order = $this->pay($this->checkout($buyer, [[$rice, 1], [$oil, 3]]))->orders()->sole();
        $this->assertSame(['70.00', '180.00'], [$order->shop_discount, $order->total]);
        $this->assertStringContainsString('Cooking pack', $order->offer_labels);
    }

    public function test_a_tip_goes_to_the_rider_who_delivered(): void
    {
        $product = $this->makeProduct(null, ['price' => '100.00']);
        $checkout = $this->pay($this->checkout($this->buyer(), [[$product, 1]], ['tips' => [$product->seller_id => 10]], 'delivery'));
        $order = $checkout->orders()->sole();
        $this->assertSame(['10.00', '130.00'], [$order->rider_tip, $order->total]); // 100 + 20 delivery + 10 tip

        $this->orders()->accept($order);
        $this->actingAs($order->seller->user)->post(route('seller.orders.rider', $order));
        $rider = User::factory()->create(['is_rider' => true]);
        $job = DeliveryJob::sole();
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'accept']));
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'picked-up']));
        preg_match('/goods: (\d{4})/', $this->sms->lastTo($order->buyer->phone), $code);
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'deliver']), ['code' => $code[1]]);
        $this->orders()->confirmDelivery($order->fresh(), $order->buyer);

        $this->assertSame('10.00', LedgerEntry::where('rider_id', $rider->id)->where('type', LedgerType::RiderTip)->value('amount'));
        $this->assertSame('95.00', LedgerEntry::where('seller_id', $order->seller_id)->where('type', LedgerType::Sale)->value('amount')); // 130 - 5 commission - 20 fee - 10 tip
    }

    public function test_extras_on_a_dish_add_to_its_price_and_show_on_the_order(): void
    {
        $dish = $this->makeProduct(null, ['title' => 'Jollof rice', 'price' => '50.00']);
        $this->actingAs($dish->seller->user)->put(route('seller.products.addons', $dish), ['addons' => [['name' => 'Extra meat', 'price' => 15], ['name' => 'Extra pepper', 'price' => 5], ['name' => '', 'price' => '']]])
            ->assertSessionHas('status');
        [$meat, $pepper] = ProductAddon::orderBy('id')->get();
        $buyer = $this->buyer();

        $this->actingAs($buyer)->get(route('products.show', $dish))->assertSee('Extra meat +Le 15.00');
        $this->actingAs($buyer)->post(route('cart.add', $dish), ['quantity' => 2, 'addons' => [$meat->id, $pepper->id]])->assertSessionHasNoErrors();
        $this->actingAs($buyer)->post(route('cart.add', $dish), ['quantity' => 1])->assertSessionHasNoErrors(); // a plain one is a separate line
        $this->assertSame(2, CartItem::count());
        $this->actingAs($buyer)->get(route('cart.index'))->assertSee('+ Extra meat, Extra pepper')->assertSee('Le 70.00 per piece');

        $order = $this->pay($this->checkout($buyer, [[$dish, 2, [$meat->id, $pepper->id]], [$dish, 1]]))->orders()->sole();
        $this->assertSame('190.00', $order->subtotal); // 2 × 70 + 50
        $this->assertSame(['Jollof rice + Extra meat, Extra pepper', 'Jollof rice'], $order->items()->orderBy('id')->get()->pluck('display_title')->all());

        try {
            app(CartService::class)->add($buyer, $this->makeProduct(), 1, null, [$meat->id]); // another product's extra
            $this->fail('Took an extra from another product.');
        } catch (MarketplaceException $e) {
            $this->assertSame('Choose extras from the list.', $e->getMessage());
        }
    }

    public function test_the_app_sees_extras_offers_and_the_discount_in_the_quote(): void
    {
        $dish = $this->makeProduct(null, ['price' => '100.00']);
        ProductAddon::create(['product_id' => $dish->id, 'name' => 'Extra meat', 'price' => '15.00']);
        ShopOffer::create(['seller_id' => $dish->seller_id, 'kind' => 'spend', 'title' => 'x', 'amount' => '10.00', 'min_spend' => '50.00', 'is_active' => true]);

        $this->getJson("/api/v1/products/{$dish->slug}")->assertJsonPath('addons.0.name', 'Extra meat')->assertJsonPath('offers.0.label', 'Le 10.00 off orders over Le 50.00');

        Sanctum::actingAs($this->buyer());
        $this->postJson('/api/v1/checkout/quote', ['product' => $dish->slug, 'qty' => 1, 'methods' => [$dish->seller_id => 'pickup'], 'promo_code' => 'NOPE'])
            ->assertOk()->assertJsonPath('groups.0.shop_discount', '10.00')->assertJsonPath('amount_due', '90.00')
            ->assertJsonPath('promo_error', 'This code does not work for the shops in your order.');
    }
}
