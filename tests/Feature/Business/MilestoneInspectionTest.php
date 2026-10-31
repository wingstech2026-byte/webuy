<?php

namespace Tests\Feature\Business;

use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Inspection;
use App\Models\Payment;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\DisputeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Big orders paid in parts, and the pre-delivery inspection before the last part. */
class MilestoneInspectionTest extends TestCase
{
    use Marketplace;

    private function bigOrder(?User $buyer = null)
    {
        $buyer ??= $this->buyer();
        $product = $this->makeProduct(null, ['price' => '1000.00', 'stock' => 10]);

        $checkout = app(CheckoutService::class)->place($buyer, [['product_id' => $product->id, 'quantity' => 3]],
            ['name' => 'B', 'phone' => $buyer->phone], [$product->seller_id => 'pickup'], Operator::Orange, $buyer->phone, null, 'app', ['payment_plan' => 'milestones']);

        return [$this->pay($checkout), $buyer];
    }

    private function approveCharge(int $paymentId): void
    {
        $this->postJson("/api/v1/payments/{$paymentId}/simulate", ['outcome' => 'approve'])->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_a_big_order_is_paid_in_parts_and_the_seller_ships_only_when_fully_paid(): void
    {
        [$checkout, $buyer] = $this->bigOrder();
        $order = $checkout->orders()->first();

        $this->assertSame('900.00', (string) $this->depositOf($checkout)->amount, '30% of Le 3,000');
        $this->assertSame(['paid', 'pending'], $order->milestones->pluck('status')->all());
        $this->assertSame('2100.00', (string) $order->milestones[1]->amount);

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/orders/{$order->id}/milestones/{$order->milestones[1]->id}/pay", ['operator' => 'ORANGE', 'payer_phone' => '076111222'])
            ->assertUnprocessable()->assertJsonPath('message', 'You can pay the balance once the seller has accepted the order.');

        $this->orders()->accept($order);

        try {
            $this->orders()->ship($order->fresh(), 'x');
            $this->fail('Shipping must wait for the balance');
        } catch (MarketplaceException $e) {
            $this->assertStringContainsString('not paid the balance', $e->getMessage());
        }

        $this->getJson("/api/v1/orders/{$order->id}")->assertJsonPath('data.can_pay_balance', true);
        $id = $this->postJson("/api/v1/orders/{$order->id}/milestones/{$order->milestones[1]->id}/pay", ['operator' => 'ORANGE', 'payer_phone' => '076111222'])
            ->assertCreated()->assertJsonPath('amount', '2100.00')->json('id');
        $this->approveCharge($id);

        $this->assertFalse($order->fresh()->hasUnpaidMilestones());
        $this->assertStringContainsString('fully paid', $this->sms->lastTo($order->seller->user->phone));
        $this->assertSame(OrderStatus::Shipped, $this->orders()->ship($order->fresh(), 'x')->status);
    }

    public function test_small_orders_cannot_be_paid_in_parts(): void
    {
        $buyer = $this->buyer();
        $product = $this->makeProduct(null, ['price' => '100.00']);

        $this->expectExceptionMessage('Paying in parts is for orders of Le 2,000.00 or more.');
        app(CheckoutService::class)->place($buyer, [['product_id' => $product->id, 'quantity' => 1]],
            ['name' => 'B', 'phone' => $buyer->phone], [$product->seller_id => 'pickup'], Operator::Orange, $buyer->phone, null, 'app', ['payment_plan' => 'milestones']);
    }

    public function test_an_inspection_must_pass_before_the_last_part_is_paid(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$checkout, $buyer] = $this->bigOrder();
        $order = $checkout->orders()->first();
        $this->orders()->accept($order);
        Sanctum::actingAs($buyer);

        $fee = $this->postJson("/api/v1/orders/{$order->id}/inspection", ['operator' => 'ORANGE', 'payer_phone' => '076111222'])
            ->assertCreated()->assertJsonPath('fee', '50.00')->json('payment.id');
        $this->approveCharge($fee);
        $inspection = Inspection::firstOrFail();
        $this->assertSame('waiting', $inspection->status);

        $balance = $order->milestones()->where('position', 2)->first();
        $this->postJson("/api/v1/orders/{$order->id}/milestones/{$balance->id}/pay", ['operator' => 'ORANGE', 'payer_phone' => '076111222'])
            ->assertUnprocessable()->assertJsonPath('message', 'Wait for the inspection report before paying the balance.');

        $agent = User::factory()->create(['is_agent' => true]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post(route('admin.inspections.assign', $inspection), ['agent_id' => $agent->id])->assertSessionHas('status');

        $this->actingAs($agent)->get(route('agent.inspections.edit', $inspection))->assertOk();
        $this->post(route('agent.inspections.update', $inspection), [
            'passed' => '1', 'report' => 'All 3 units checked, sealed and as described.', 'photos' => [UploadedFile::fake()->image('goods.jpg')],
        ])->assertRedirect();
        $this->assertSame('passed', $inspection->fresh()->status);
        $this->assertStringContainsString('PASSED', $this->sms->lastTo($buyer->phone));

        Sanctum::actingAs($buyer);
        $this->postJson("/api/v1/orders/{$order->id}/milestones/{$balance->id}/pay", ['operator' => 'ORANGE', 'payer_phone' => '076111222'])->assertCreated();
    }

    public function test_a_failed_delivery_refunds_every_part_through_the_dispute_process(): void
    {
        [$checkout, $buyer] = $this->bigOrder();
        $order = $checkout->orders()->first();
        $this->orders()->accept($order);

        Sanctum::actingAs($buyer);
        $id = $this->postJson("/api/v1/orders/{$order->id}/milestones/{$order->milestones[1]->id}/pay", ['operator' => 'ORANGE', 'payer_phone' => '076111222'])->json('id');
        $this->approveCharge($id);

        $dispute = app(DisputeService::class)->open($order->fresh(), $buyer, 'not_received', 'The seller never delivered the goods to me.');
        app(DisputeService::class)->resolve($dispute, User::factory()->admin()->create(), 'buyer', 'Seller did not deliver');

        $refunds = Payment::where('order_id', $order->id)->where('kind', 'refund')->orderBy('id')->get();
        $this->assertSame(['900.00', '2100.00'], $refunds->pluck('amount')->map(fn ($a) => (string) $a)->all());
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
    }
}
