<?php

namespace Tests\Feature;

use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Services\DisputeService;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Returns after delivery: the seller answers, the items come back, the buyer is refunded exactly once. */
class ReturnTest extends TestCase
{
    use Marketplace;

    private Product $rice;

    private Product $oil;

    private User $buyer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $seller = $this->makeSeller();
        $this->rice = $this->makeProduct($seller, ['price' => '100.00', 'stock' => 10]);
        $this->oil = $this->makeProduct($seller, ['price' => '50.00', 'stock' => 10]);
        $this->buyer = $this->buyer();

        // 2 rice + 1 oil, picked up: Le 250.00.
        $checkout = $this->pay($this->placeOrder($this->buyer, [[$this->rice, 2], [$this->oil, 1]]));
        $this->order = $checkout->orders()->firstOrFail();
        $this->orders()->accept($this->order);
        $this->orders()->ship($this->order);
        $this->order = $this->orders()->markDelivered($this->order);
    }

    /** @param array<int, int> $quantities product id => quantity */
    private function askReturn(array $quantities, ?User $as = null): TestResponse
    {
        $items = $this->order->items->mapWithKeys(fn ($item) => [$item->id => $quantities[$item->product_id] ?? 0])->all();

        return $this->actingAs($as ?? $this->buyer)->post(route('returns.store', $this->order), [
            'items' => $items, 'reason' => 'damaged', 'description' => 'The bag was torn and wet.',
        ]);
    }

    private function seller(): User
    {
        return $this->order->seller->user;
    }

    public function test_a_partial_return_refunds_the_returned_share_restocks_and_pays_the_seller_for_the_rest(): void
    {
        $this->askReturn([$this->rice->id => 1])->assertRedirect(route('orders.show', $this->order));

        $return = ReturnRequest::sole();
        $this->assertSame('100.00', $return->refund_amount);
        $this->assertFalse($return->is_full);
        $this->assertSame(OrderStatus::Returning, $this->order->fresh()->status);

        $this->artisan('wibuy:complete-orders');
        $this->travel(4)->days();
        $this->artisan('wibuy:complete-orders');
        $this->assertSame(OrderStatus::Returning, $this->order->fresh()->status, 'A paused order must not complete by itself.');
        $this->travelBack();

        $this->actingAs($this->seller())->post(route('seller.returns.accept', $return), ['instructions' => 'Bring it to the shop.'])->assertRedirect();
        $this->actingAs($this->seller())->post(route('seller.returns.received', $return))->assertRedirect();
        $this->actingAs($this->seller())->post(route('seller.returns.received', $return))->assertSessionHasErrors('general');

        $this->assertSame(9, $this->rice->fresh()->stock);
        $this->assertSame(['100.00'], Payment::where('kind', 'refund')->pluck('amount')->all());
        $order = $this->order->fresh();
        $this->assertSame(OrderStatus::Completed, $order->status);
        $commission = bcdiv(bcmul($order->commission, '150.00', 6), $order->total, 2);
        $this->assertSame(bcsub('150.00', $commission, 2), LedgerEntry::where('order_id', $order->id)->sole()->amount);
    }

    public function test_a_full_return_refunds_everything_and_the_seller_gets_nothing(): void
    {
        $this->askReturn([$this->rice->id => 2, $this->oil->id => 1]);
        $return = ReturnRequest::sole();
        $this->assertTrue($return->is_full);

        $this->actingAs($this->seller())->post(route('seller.returns.accept', $return), ['instructions' => 'Our rider collects it.']);
        $this->actingAs($this->seller())->post(route('seller.returns.received', $return));

        $this->assertSame(OrderStatus::Refunded, $this->order->fresh()->status);
        $this->assertSame(['250.00'], Payment::where('kind', 'refund')->pluck('amount')->all());
        $this->assertSame(0, LedgerEntry::where('order_id', $this->order->id)->count());
        $this->assertSame([10, 10], [$this->rice->fresh()->stock, $this->oil->fresh()->stock]);
    }

    public function test_a_refused_return_goes_to_the_wibuy_team_who_can_refund_the_buyer(): void
    {
        $this->askReturn([$this->oil->id => 1]);
        $return = ReturnRequest::sole();

        $this->actingAs($this->seller())->post(route('seller.returns.refuse', $return), ['note' => 'It was fine when it left.'])->assertRedirect();

        $return->refresh();
        $this->assertSame(ReturnRequest::ESCALATED, $return->status);
        $this->assertSame(OrderStatus::Disputed, $this->order->fresh()->status);
        $this->assertStringContainsString('It was fine when it left.', Dispute::sole()->description);

        app(DisputeService::class)->resolve(Dispute::sole(), User::factory()->admin()->create(), 'buyer', 'Photos show damage');
        $this->assertSame(DisputeStatus::ResolvedBuyer, Dispute::sole()->status);
        $this->assertSame(OrderStatus::Refunded, $this->order->fresh()->status);
    }

    public function test_a_seller_who_does_not_answer_in_time_hands_the_return_to_the_wibuy_team(): void
    {
        $this->askReturn([$this->oil->id => 1]);

        $this->travel(47)->hours();
        $this->artisan('wibuy:returns-tick');
        $this->assertSame(ReturnRequest::REQUESTED, ReturnRequest::sole()->status);

        $this->travel(2)->hours();
        $this->artisan('wibuy:returns-tick');
        $this->artisan('wibuy:returns-tick');

        $this->assertSame(ReturnRequest::ESCALATED, ReturnRequest::sole()->status);
        $this->assertSame(1, Dispute::count());
    }

    public function test_return_rules(): void
    {
        $this->askReturn([$this->rice->id => 3])->assertSessionHasErrors('items');
        $this->askReturn([])->assertSessionHasErrors('items');
        $this->askReturn([$this->rice->id => 1], $this->buyer())->assertForbidden();

        $this->askReturn([$this->rice->id => 1])->assertRedirect();
        $this->askReturn([$this->oil->id => 1])->assertSessionHasErrors('items');
        $this->assertSame(1, ReturnRequest::count());

        $otherSeller = $this->makeSeller()->user;
        $this->actingAs($otherSeller)->post(route('seller.returns.accept', ReturnRequest::sole()), ['instructions' => 'Bring it here.'])->assertNotFound();
        $this->actingAs($this->seller())->post(route('seller.returns.received', ReturnRequest::sole()))->assertSessionHasErrors('general');
    }

    public function test_the_app_asks_for_a_return_and_the_seller_answers_through_the_api(): void
    {
        $riceItem = $this->order->items->firstWhere('product_id', $this->rice->id);
        Sanctum::actingAs($this->buyer);

        $this->getJson("/api/v1/orders/{$this->order->id}")->assertOk()->assertJsonPath('data.can_return', true);
        $this->postJson("/api/v1/orders/{$this->order->id}/return", [
            'items' => [$riceItem->id => 2], 'reason' => 'wrong_item', 'description' => 'I ordered a different brand.',
        ])->assertCreated()->assertJsonPath('refund_amount', '200.00');

        $returnId = ReturnRequest::sole()->id;
        Sanctum::actingAs($this->seller());
        $this->postJson("/api/v1/seller/returns/{$returnId}/accept", [])->assertUnprocessable()->assertJsonValidationErrors('instructions');
        $this->postJson("/api/v1/seller/returns/{$returnId}/accept", ['instructions' => 'Bring it to the shop.'])
            ->assertOk()->assertJsonPath('status', ReturnRequest::ACCEPTED);

        Sanctum::actingAs($this->buyer);
        $this->getJson("/api/v1/orders/{$this->order->id}")->assertOk()
            ->assertJsonPath('data.can_return', false)
            ->assertJsonPath('data.return.return_instructions', 'Bring it to the shop.');
    }

    public function test_only_a_delivered_order_can_be_returned(): void
    {
        $paid = $this->paidOrder(null, $this->buyer);

        $this->actingAs($this->buyer)->get(route('returns.create', $paid))->assertNotFound();
        $this->actingAs($this->buyer)->get(route('returns.create', $this->order))->assertOk()->assertSee('Return items');
    }
}
