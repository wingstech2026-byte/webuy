<?php

namespace Tests\Feature;

use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\GatewayResult;
use App\Payments\PaymentGateway;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use Marketplace;

    public function test_seller_accepts_ships_and_marks_delivered_and_the_buyer_is_texted_each_time(): void
    {
        $order = $this->paidOrder(method: 'delivery');
        $seller = $order->seller->user;
        $buyer = $order->buyer;

        $this->actingAs($seller)->get(route('seller.orders.index'))->assertOk()->assertSee($order->number)->assertSee('Accept by');

        $this->actingAs($seller)->post(route('seller.orders.accept', $order))->assertRedirect(route('seller.orders.show', $order));
        $this->assertSame(OrderStatus::Accepted, $order->fresh()->status);
        $this->assertStringContainsString('accepted your order', $this->sms->lastTo($buyer->phone));

        $this->actingAs($seller)->post(route('seller.orders.ship', $order), ['reason' => 'Rider Ibrahim, arriving 3pm']);
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
        $this->assertSame('Rider Ibrahim, arriving 3pm', $order->fresh()->delivery_note);
        $this->assertStringContainsString('is on its way', $this->sms->lastTo($buyer->phone));

        $this->actingAs($seller)->post(route('seller.orders.deliver', $order));
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertStringContainsString('Please confirm you got it', $this->sms->lastTo($buyer->phone));

        // Timeline for the buyer.
        $this->actingAs($buyer)->get(route('orders.show', $order))->assertOk()
            ->assertSeeInOrder(['Ordered and paid', 'Seller accepted', 'Shipped', 'Delivered', 'Completed'])
            ->assertSee('Yes, confirm delivery')->assertSee('Rider Ibrahim');
    }

    public function test_pickup_orders_are_ready_for_pickup_instead_of_shipped(): void
    {
        $order = $this->paidOrder(method: 'pickup');
        $this->orders()->accept($order);

        $this->actingAs($order->seller->user)->post(route('seller.orders.ship', $order));

        $this->assertStringContainsString('is ready for pickup at '.$order->seller->pickup_address, $this->sms->lastTo($order->buyer->phone));
        $this->actingAs($order->buyer)->get(route('orders.index'))->assertSee('Ready for pickup');
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $order = $this->paidOrder();
        $seller = $order->seller->user;

        $this->actingAs($seller)->post(route('seller.orders.ship', $order))->assertSessionHasErrors('general');
        $this->actingAs($seller)->post(route('seller.orders.deliver', $order))->assertSessionHasErrors('general');
        $this->actingAs($order->buyer)->post(route('orders.confirm', $order))->assertSessionHasErrors('general');

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_sellers_and_buyers_only_see_their_own_orders(): void
    {
        $order = $this->paidOrder();
        $otherSeller = $this->makeSeller();
        $stranger = $this->buyer();

        $this->actingAs($otherSeller->user)->get(route('seller.orders.show', $order))->assertForbidden();
        $this->actingAs($otherSeller->user)->post(route('seller.orders.accept', $order))->assertForbidden();
        $this->actingAs($otherSeller->user)->get(route('seller.orders.index'))->assertDontSee($order->number);
        $this->actingAs($stranger)->get(route('orders.show', $order))->assertForbidden();
        $this->actingAs($stranger)->post(route('orders.confirm', $order))->assertForbidden();
        $this->actingAs($order->seller->user)->post(route('orders.confirm', $order))->assertForbidden();

        $this->actingAs($order->buyer)->get(route('orders.show', $order))->assertOk();
        $this->actingAs($order->seller->user)->get(route('seller.orders.show', $order))->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.orders.show', $order))->assertOk();
    }

    // ---------------------------------------------------------------------- refunds

    public function test_rejecting_a_paid_order_refunds_the_buyer_automatically(): void
    {
        $product = $this->makeProduct(null, ['price' => '250.00', 'stock' => 10]);
        $order = $this->paidOrder($product, quantity: 2);

        $this->actingAs($order->seller->user)->post(route('seller.orders.reject', $order), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($order->seller->user)->post(route('seller.orders.reject', $order), ['reason' => 'Sold out at the market'])->assertRedirect();

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status); // the fake gateway completes refunds at once
        $this->assertSame('Sold out at the market', $order->rejection_reason);
        $this->assertNotNull($order->rejected_at);

        $refund = $order->payments()->where('kind', 'refund')->sole();
        $this->assertSame('500.00', $refund->amount);
        $this->assertSame(PaymentStatus::Completed, $refund->status);
        $this->assertSame($order->checkout->payer_phone, $refund->phone);

        $this->assertStringContainsString('We refunded Le 500.00', $this->sms->lastTo($order->buyer->phone));
        $this->assertSame(0, LedgerEntry::count(), 'the seller is never credited');
    }

    public function test_an_order_not_accepted_in_24_hours_is_refunded_automatically(): void
    {
        $order = $this->paidOrder();
        $kept = $this->paidOrder();
        $this->orders()->accept($kept);

        $this->travel(23)->hours();
        Artisan::call('wibuy:refund-unaccepted');
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);

        $this->travel(2)->hours();
        Artisan::call('wibuy:refund-unaccepted');

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame('The seller did not accept your order in time.', $order->fresh()->refund_reason);
        $this->assertSame(OrderStatus::Accepted, $kept->fresh()->status);

        // Running it twice refunds once.
        Artisan::call('wibuy:refund-unaccepted');
        $this->assertSame(1, $order->payments()->where('kind', 'refund')->count());

        // And the seller can no longer accept it.
        $this->actingAs($order->seller->user)->post(route('seller.orders.accept', $order))->assertSessionHasErrors('general');
    }

    public function test_a_seller_cannot_accept_after_the_deadline_even_before_the_job_runs(): void
    {
        $order = $this->paidOrder();
        $this->travel(25)->hours();

        $this->actingAs($order->seller->user)->post(route('seller.orders.accept', $order))
            ->assertSessionHasErrors(['general' => 'The time to accept this order has passed. The buyer will be refunded.']);
    }

    public function test_a_failed_refund_can_be_retried_by_an_admin(): void
    {
        $order = $this->paidOrder();
        $this->app->instance(PaymentGateway::class, new class extends FakeGateway
        {
            public function initiateRefund(Payment $refund, Payment $deposit): GatewayResult
            {
                return GatewayResult::failed('Insufficient float');
            }
        });

        $this->orders()->reject($order, 'Out of stock');
        $this->assertSame(OrderStatus::Rejected, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Failed, $order->payments()->where('kind', 'refund')->sole()->status);

        $this->app->instance(PaymentGateway::class, new FakeGateway);
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.orders.retry-refund', $order))->assertRedirect();

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
        $this->assertSame(2, $order->payments()->where('kind', 'refund')->count());
    }

    // ------------------------------------------------------------- completion and money

    public function test_confirming_delivery_completes_the_order_and_credits_the_seller_ledger(): void
    {
        $product = $this->makeProduct($this->makeSeller(['Western Area Urban' => '30.00']), ['price' => '200.00', 'stock' => 10]);
        $order = $this->paidOrder($product, quantity: 3, method: 'delivery'); // 600 + 30 delivery, 5% commission on 600 = 30
        $this->orders()->accept($order);
        $this->orders()->ship($order);

        $ledger = app(LedgerService::class);
        $this->assertSame('600.00', $ledger->pending($order->seller), 'held until completion: 630 - 30');
        $this->assertSame('0.00', $ledger->available($order->seller));

        $this->actingAs($order->buyer)->post(route('orders.confirm', $order))->assertRedirect(route('orders.show', $order));

        $order->refresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $this->assertNotNull($order->completed_at);

        $entry = LedgerEntry::sole();
        $this->assertSame(LedgerType::Sale, $entry->type);
        $this->assertSame('600.00', $entry->amount); // 630 total - 30 commission
        $this->assertSame($order->id, $entry->order_id);
        $this->assertSame('600.00', $ledger->available($order->seller));
        $this->assertSame('0.00', $ledger->pending($order->seller));
        $this->assertSame(3, $product->fresh()->sold_count);

        // Confirming again does nothing.
        $this->actingAs($order->buyer)->post(route('orders.confirm', $order))->assertSessionHasErrors('general');
        app(LedgerService::class)->creditSale($order);
        $this->assertSame(1, LedgerEntry::count());

        $this->actingAs($order->seller->user)->get(route('seller.earnings'))->assertOk()->assertSee('Le 600.00')->assertSee("Order {$order->number} completed");
    }

    public function test_orders_complete_by_themselves_three_days_after_shipping_or_delivery(): void
    {
        $shipped = $this->shippedOrder();
        $delivered = $this->shippedOrder();
        $this->travel(1)->days();
        $this->orders()->markDelivered($delivered);

        $this->travel(2)->days(); // shipped: 3 days ago; delivered: 2 days ago
        $this->travel(1)->hours();
        Artisan::call('wibuy:complete-orders');

        $this->assertSame(OrderStatus::Completed, $shipped->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $delivered->fresh()->status);

        $this->travel(1)->days();
        Artisan::call('wibuy:complete-orders');
        $this->assertSame(OrderStatus::Completed, $delivered->fresh()->status);

        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_the_seller_dashboard_shows_counts_and_balances(): void
    {
        $seller = $this->makeSeller();
        $this->paidOrder($this->makeProduct($seller, ['price' => '100.00']));

        $this->actingAs($seller->user)->get(route('seller.dashboard'))->assertOk()
            ->assertSee('New (accept them!)')->assertSee('Le 95.00'); // pending: 100 - 5 commission
    }
}
