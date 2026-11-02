<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Exceptions\MarketplaceException;
use App\Models\AppNotification;
use App\Models\CartItem;
use App\Models\DeliveryJob;
use App\Models\ProductQuestion;
use App\Models\User;
use App\Services\CartService;
use App\Services\LedgerService;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Questions, cart and reorder reminders, low stock, holiday mode, sales page, receipts, ordering for family, shop helpers. */
class ShopToolsTest extends TestCase
{
    use Marketplace;

    public function test_a_buyer_asks_the_shop_answers_and_everyone_sees_it_but_phone_numbers_are_refused(): void
    {
        $product = $this->makeProduct(null, ['title' => 'Solar lamp']);
        $buyer = $this->buyer();

        $this->actingAs($buyer)->post(route('products.questions.store', $product), ['question' => 'Call me on 076 123 456'])->assertSessionHasErrors();
        $this->actingAs($buyer)->post(route('products.questions.store', $product), ['question' => 'How long does the battery last?'])->assertSessionHas('status');
        $this->get(route('products.show', $product))->assertDontSee('How long does the battery last?'); // not answered yet

        $question = ProductQuestion::sole();
        $this->actingAs($product->seller->user)->post(route('seller.questions.answer', $question), ['answer' => 'About 8 hours.'])->assertSessionHas('status');

        $this->get(route('products.show', $product))->assertSee('How long does the battery last?')->assertSee('About 8 hours.');
        $this->assertSame('💬 The shop answered', AppNotification::where('user_id', $buyer->id)->latest('id')->value('title'));
    }

    public function test_a_cart_left_for_a_day_is_reminded_once_until_it_changes(): void
    {
        $buyer = $this->buyer();
        app(CartService::class)->add($buyer, $this->makeProduct(), 1);

        $this->artisan('wibuy:cart-reminders');
        $this->assertSame(0, AppNotification::where('user_id', $buyer->id)->count()); // not a day yet

        $this->travel(25)->hours();
        $this->artisan('wibuy:cart-reminders');
        $this->artisan('wibuy:cart-reminders');
        $this->assertSame(1, AppNotification::where('user_id', $buyer->id)->where('title', '🛒 Still want these?')->count());

        $this->travel(1)->hours();
        CartItem::query()->update(['quantity' => 2, 'updated_at' => now()]);
        $this->travel(25)->hours();
        $this->artisan('wibuy:cart-reminders');
        $this->assertSame(2, AppNotification::where('user_id', $buyer->id)->where('title', '🛒 Still want these?')->count());
    }

    public function test_the_shop_hears_once_when_a_product_runs_low_and_again_after_a_restock(): void
    {
        $product = $this->makeProduct(null, ['stock' => 7]);
        $owner = $product->seller->user;

        $this->paidOrder($product, null, 1); // 6 left: fine
        $this->paidOrder($product, null, 2); // 4 left: low
        $this->paidOrder($product, null, 1); // 3 left: already told
        $this->assertSame(1, AppNotification::where('user_id', $owner->id)->where('title', '📉 Running low')->count());
        $this->assertStringContainsString('Only 4 left', implode(' | ', $this->sms->messagesTo($owner->phone)));

        $product->refresh()->update(['stock' => 20]);
        $product->refresh()->update(['stock' => 3]);
        $this->paidOrder($product, null, 1);
        $this->assertSame(2, AppNotification::where('user_id', $owner->id)->where('title', '📉 Running low')->count());
    }

    public function test_a_shop_on_holiday_shows_its_products_but_takes_no_orders(): void
    {
        $product = $this->makeProduct();
        $seller = $product->seller;

        $this->actingAs($seller->user)->put(route('seller.shop.holiday'), ['holiday_until' => now()->addDays(5)->toDateString(), 'holiday_message' => 'Family wedding'])->assertSessionHas('status');

        $this->get(route('products.show', $product))->assertOk()->assertSee('is closed until')->assertSee('Family wedding');
        $this->assertSame('The shop is closed until '.now()->addDays(5)->format('j M'), app(CartService::class)->problemFor($product->fresh(), 1));

        try {
            $this->placeOrder($this->buyer(), [[$product->fresh(), 1]]);
            $this->fail('A shop on holiday took an order.');
        } catch (MarketplaceException $e) {
            $this->assertStringContainsString('Family wedding', $e->getMessage());
        }

        $this->actingAs($seller->user)->put(route('seller.shop.holiday'), ['holiday_until' => ''])->assertSessionHas('status');
        $this->assertNull(app(CartService::class)->problemFor($product->fresh(), 1));
    }

    public function test_finance_sees_the_sales_page_and_support_does_not(): void
    {
        $this->paidOrder($this->makeProduct(null, ['price' => '150.00']));

        $this->actingAs(User::factory()->admin(AdminRole::Finance)->create())->get(route('admin.analytics'))
            ->assertOk()->assertSee('Sales, last 30 days')->assertSee('Le 150.00');
        $this->actingAs(User::factory()->admin(AdminRole::Support)->create())->get(route('admin.analytics'))->assertForbidden();
    }

    public function test_buyer_and_shop_print_a_receipt_but_strangers_cannot(): void
    {
        $order = $this->paidOrder($this->makeProduct(null, ['title' => 'Bag of rice']));

        $this->actingAs($order->buyer)->get(route('orders.receipt', $order))->assertOk()->assertSee('Receipt for order '.$order->number)->assertSee('Bag of rice');
        $this->actingAs($order->seller->user)->get(route('orders.receipt', $order))->assertOk();
        $this->actingAs($this->buyer())->get(route('orders.receipt', $order))->assertForbidden();
    }

    public function test_buy_again_fills_the_cart_and_a_reorder_reminder_comes_when_due(): void
    {
        $rice = $this->makeProduct(null, ['title' => 'Rice', 'stock' => 10]);
        $soap = $this->makeProduct($rice->seller, ['title' => 'Soap', 'stock' => 10]);
        $checkout = $this->pay($this->placeOrder($buyer = $this->buyer(), [[$rice, 2], [$soap, 1]]));
        $order = $checkout->orders()->sole();
        $soap->update(['status' => 'draft']);

        $this->actingAs($buyer)->post(route('orders.buy-again', $order))->assertRedirect(route('cart.index'))
            ->assertSessionHas('status', 'Added 1 item(s) to your cart. Not available now: Soap.');
        $this->assertSame(2, CartItem::where('product_id', $rice->id)->value('quantity'));

        $this->actingAs($buyer)->post(route('orders.repeat', $order), ['every_days' => 14])->assertSessionHas('status');
        $this->actingAs($this->buyer())->post(route('orders.repeat', $order), ['every_days' => 14])->assertForbidden();

        $this->travel(15)->days();
        $this->artisan('wibuy:repeat-reminders');
        $this->artisan('wibuy:repeat-reminders');
        $this->assertSame(1, AppNotification::where('user_id', $buyer->id)->where('title', '🔁 Time to order again?')->count());
    }

    public function test_an_order_for_family_sends_the_delivery_code_to_the_person_receiving_it(): void
    {
        $order = $this->paidOrder($this->makeProduct(), null, 1, 'delivery');
        $order->forceFill(['recipient_name' => 'Mama', 'recipient_phone' => '23277555444'])->save();
        $this->orders()->accept($order);
        $this->actingAs($order->seller->user)->post(route('seller.orders.rider', $order));

        $rider = User::factory()->create(['is_rider' => true]);
        $job = DeliveryJob::sole();
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'accept']));
        $this->actingAs($rider)->post(route('rider.jobs.act', [$job, 'picked-up']));

        preg_match('/goods: (\d{4})/', $this->sms->lastTo($order->buyer->phone), $buyerCode);
        $this->assertStringContainsString("{$order->buyer->name} sent you order {$order->number}", $this->sms->lastTo('23277555444'));
        $this->assertStringContainsString($buyerCode[1], $this->sms->lastTo('23277555444'));
    }

    public function test_a_helper_manages_orders_and_products_but_never_the_money_or_settings(): void
    {
        $order = $this->paidOrder();
        $seller = $order->seller;
        $helper = $this->buyer(['phone' => '23277888999']);
        app(LedgerService::class)->adjust($seller, '50.00', 'Opening balance');

        $this->actingAs($this->buyer())->post(route('seller.staff.store'), ['phone' => '077 888 999'])->assertRedirect(); // not an owner
        $this->actingAs($seller->user)->post(route('seller.staff.store'), ['phone' => '077 888 999'])->assertSessionHas('status');
        $this->actingAs($seller->user)->post(route('seller.staff.store'), ['phone' => '076 000 001'])->assertSessionHasErrors('phone'); // no account

        $helper = $helper->fresh();
        $this->actingAs($helper)->get(route('seller.dashboard'))->assertOk()->assertSee('Helper')->assertDontSee('Available to pay out');
        $this->actingAs($helper)->post(route('seller.orders.accept', $order))->assertSessionHasNoErrors();
        $this->assertSame('accepted', $order->fresh()->status->value);
        $this->actingAs($helper)->get(route('seller.products.index'))->assertOk();

        $this->actingAs($helper)->get(route('seller.earnings'))->assertForbidden();
        $this->actingAs($helper)->post(route('seller.payouts.store'), [])->assertForbidden();
        $this->actingAs($helper)->get(route('seller.shop'))->assertForbidden();
        $this->actingAs($helper)->getJson('/api/v1/seller/dashboard')->assertOk()->assertJsonPath('money', null)->assertJsonPath('is_owner', false);
        $this->actingAs($helper)->getJson('/api/v1/seller/earnings')->assertForbidden();

        $this->actingAs($seller->user)->delete(route('seller.staff.destroy', $helper))->assertSessionHas('status');
        $this->actingAs($helper->fresh())->get(route('seller.dashboard'))->assertRedirect(route('sell.apply'));
    }
}
