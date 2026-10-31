<?php

namespace Tests\Feature;

use App\Enums\DisputeStatus;
use App\Enums\OrderStatus;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class DisputeTest extends TestCase
{
    use Marketplace;

    private function openDispute($order = null): Dispute
    {
        $order ??= $this->shippedOrder();

        $this->actingAs($order->buyer)->post(route('disputes.store', $order), [
            'reason' => 'damaged',
            'description' => 'Two of the bags were torn and wet.',
        ])->assertRedirect();

        return Dispute::sole();
    }

    public function test_the_buyer_reports_a_problem_with_photos_and_the_seller_is_texted(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $order = $this->shippedOrder();

        $this->actingAs($order->buyer)->get(route('disputes.create', $order))->assertOk()->assertSee('What went wrong?');

        $this->actingAs($order->buyer)->post(route('disputes.store', $order), [
            'reason' => 'damaged',
            'description' => 'Two of the bags were torn and wet.',
            'photos' => [UploadedFile::fake()->image('bag.jpg', 2000, 1500), UploadedFile::fake()->image('bag2.jpg')],
        ])->assertRedirect();

        $dispute = Dispute::sole();
        $this->assertSame(DisputeStatus::Open, $dispute->status);
        $this->assertSame('The items are damaged or broken', $dispute->reason);
        $this->assertCount(2, $dispute->photos);
        $this->assertTrue($dispute->photos->every(fn ($p) => $p->isReady()));

        $order->refresh();
        $this->assertSame(OrderStatus::Disputed, $order->status);
        $this->assertSame('shipped', $order->status_before_dispute);
        $this->assertStringContainsString('reported a problem with order '.$order->number, $this->sms->lastTo($order->seller->user->phone));

        $this->actingAs($order->buyer)->get(route('disputes.show', $dispute))->assertOk()->assertSee('torn and wet');
    }

    public function test_funds_stay_on_hold_while_a_dispute_is_open(): void
    {
        $order = $this->shippedOrder();
        $this->openDispute($order);

        $this->travel(10)->days();
        Artisan::call('wibuy:complete-orders');

        $this->assertSame(OrderStatus::Disputed, $order->fresh()->status);
        $this->assertSame(0, LedgerEntry::count());
        $this->actingAs($order->buyer)->post(route('orders.confirm', $order))->assertSessionHasErrors('general');
    }

    public function test_the_seller_responds_with_their_side(): void
    {
        $dispute = $this->openDispute();
        $sellerUser = $dispute->seller->user;

        $this->actingAs($sellerUser)->get(route('disputes.show', $dispute))->assertOk()->assertSee('Your reply');

        $this->actingAs($sellerUser)->post(route('seller.disputes.respond', $dispute), ['response' => 'They were fine when they left the shop.'])
            ->assertRedirect(route('disputes.show', $dispute));

        $this->assertSame('They were fine when they left the shop.', $dispute->fresh()->seller_response);
        $this->assertNotNull($dispute->fresh()->seller_responded_at);
    }

    public function test_only_the_people_involved_can_see_or_answer_a_dispute(): void
    {
        $dispute = $this->openDispute();
        $other = $this->makeSeller();

        $this->actingAs($this->buyer())->get(route('disputes.show', $dispute))->assertForbidden();
        $this->actingAs($other->user)->get(route('disputes.show', $dispute))->assertForbidden();
        $this->actingAs($other->user)->post(route('seller.disputes.respond', $dispute), ['response' => 'Not mine but hello'])->assertForbidden();
        $this->actingAs($dispute->buyer)->post(route('seller.disputes.respond', $dispute), ['response' => 'I am the buyer'])->assertRedirect(route('sell.apply'));
        $this->actingAs($this->buyer())->post(route('disputes.store', $dispute->order), ['reason' => 'other', 'description' => 'Not my order at all'])->assertForbidden();
    }

    public function test_admin_decides_for_the_buyer_and_the_buyer_is_refunded(): void
    {
        $dispute = $this->openDispute();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.disputes.index'))->assertOk()->assertSee($dispute->order->number);
        $this->actingAs($admin)->post(route('admin.disputes.resolve', $dispute), ['decision' => 'buyer', 'note' => 'Photos show damage'])
            ->assertRedirect(route('admin.disputes.index'));

        $dispute->refresh();
        $this->assertSame(DisputeStatus::ResolvedBuyer, $dispute->status);
        $this->assertSame($admin->id, $dispute->resolved_by);
        $this->assertSame(OrderStatus::Refunded, $dispute->order->fresh()->status);
        $this->assertSame(0, LedgerEntry::count(), 'the seller is not paid');
        $this->assertStringContainsString('the buyer is refunded', $this->sms->lastTo($dispute->seller->user->phone));
    }

    public function test_admin_decides_for_the_seller_and_the_money_is_released(): void
    {
        $dispute = $this->openDispute();

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.disputes.resolve', $dispute), ['decision' => 'seller']);

        $this->assertSame(DisputeStatus::ResolvedSeller, $dispute->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $dispute->order->fresh()->status);
        $this->assertSame('95.00', LedgerEntry::sole()->amount);
    }

    public function test_a_dispute_can_only_be_decided_once_and_only_by_an_admin(): void
    {
        $dispute = $this->openDispute();
        $admin = User::factory()->admin()->create();

        $this->actingAs($dispute->buyer)->post(route('admin.disputes.resolve', $dispute), ['decision' => 'buyer'])->assertForbidden();
        $this->actingAs($admin)->post(route('admin.disputes.resolve', $dispute), ['decision' => 'nobody'])->assertSessionHasErrors('decision');

        $this->actingAs($admin)->post(route('admin.disputes.resolve', $dispute), ['decision' => 'seller']);
        $this->actingAs($admin)->post(route('admin.disputes.resolve', $dispute), ['decision' => 'buyer'])->assertSessionHasErrors('general');

        $this->assertSame(OrderStatus::Completed, $dispute->order->fresh()->status);
        $this->assertSame(0, $dispute->order->payments()->where('kind', 'refund')->count());
    }

    public function test_problems_can_only_be_reported_on_orders_in_progress(): void
    {
        $paidNotAccepted = $this->paidOrder();
        $this->actingAs($paidNotAccepted->buyer)->post(route('disputes.store', $paidNotAccepted), [
            'reason' => 'other', 'description' => 'The seller is slow',
        ])->assertSessionHasErrors('general');

        $completed = $this->shippedOrder();
        $this->orders()->complete($completed);
        $this->actingAs($completed->buyer)->get(route('disputes.create', $completed))->assertRedirect(route('orders.show', $completed));

        $this->assertSame(0, Dispute::count());
    }

    public function test_the_report_form_is_validated(): void
    {
        $order = $this->shippedOrder();

        $this->actingAs($order->buyer)->post(route('disputes.store', $order), [])->assertSessionHasErrors(['reason', 'description']);
        $this->actingAs($order->buyer)->post(route('disputes.store', $order), ['reason' => 'made_up', 'description' => 'Something bad happened'])->assertSessionHasErrors('reason');
        $this->assertSame(0, Dispute::count());
    }
}
