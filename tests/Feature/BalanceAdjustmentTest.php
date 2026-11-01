<?php

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\LedgerType;
use App\Enums\OrderStatus;
use App\Models\DeliveryJob;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\LedgerService;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Finance corrects a shop's or rider's balance, and pays riders for orders refunded after delivery. */
class BalanceAdjustmentTest extends TestCase
{
    use Marketplace;

    public function test_finance_adds_to_and_takes_from_a_balance_but_never_below_zero(): void
    {
        $seller = $this->makeSeller();
        $finance = User::factory()->admin(AdminRole::Finance)->create();
        $ledger = app(LedgerService::class);
        $form = fn (array $o) => $o + ['for' => 'seller', 'phone' => $seller->user->display_phone, 'reason' => 'Wrong commission on WB-1'];

        $this->actingAs($finance)->get(route('admin.payouts.index', ['for' => 'rider', 'phone' => '076 1', 'amount' => '20']))->assertOk()->assertSee('Adjust a balance');

        $this->actingAs($finance)->post(route('admin.payouts.adjust'), $form(['amount' => '25.50']))->assertSessionHas('status');
        $this->assertSame('25.50', $ledger->available($seller));
        $this->assertSame('Adjustment by WiBuy: Wrong commission on WB-1', LedgerEntry::sole()->description);

        $this->actingAs($finance)->post(route('admin.payouts.adjust'), $form(['amount' => '-30']))
            ->assertSessionHasErrors(['amount' => 'Their balance is only Le 25.50, so you cannot take away more than that.']);
        $this->actingAs($finance)->post(route('admin.payouts.adjust'), $form(['amount' => '-25.50']))->assertSessionHas('status');
        $this->assertSame('0.00', $ledger->available($seller));

        $this->actingAs($finance)->post(route('admin.payouts.adjust'), $form(['amount' => '0']))->assertSessionHasErrors('amount');
        $this->actingAs($finance)->post(route('admin.payouts.adjust'), $form(['amount' => '5', 'reason' => '']))->assertSessionHasErrors('reason');
        $this->actingAs($finance)->post(route('admin.payouts.adjust'), $form(['amount' => '5', 'phone' => $this->buyer()->display_phone]))
            ->assertSessionHasErrors(['phone' => 'No shop belongs to this number.']);

        // A rider's balance.
        $rider = User::factory()->create(['is_rider' => true]);
        $this->actingAs($finance)->post(route('admin.payouts.adjust'), ['for' => 'rider', 'phone' => $rider->display_phone, 'amount' => '15', 'reason' => 'Fuel bonus for Bo trip']);
        $this->assertSame('15.00', $ledger->riderAvailable($rider));

        // Support staff cannot move money.
        $this->actingAs(User::factory()->admin(AdminRole::Support)->create())->post(route('admin.payouts.adjust'), $form(['amount' => '99']))->assertForbidden();
        $this->assertSame(3, LedgerEntry::count());
    }

    public function test_a_rider_is_paid_once_for_a_delivery_refunded_afterwards(): void
    {
        $rider = User::factory()->create(['is_rider' => true, 'name' => 'Ibrahim Rider']);
        $order = $this->paidOrder($this->makeProduct(), null, 1, 'delivery');
        $this->orders()->accept($order);
        $job = DeliveryJob::create([
            'order_id' => $order->id, 'seller_id' => $order->seller_id, 'rider_id' => $rider->id, 'status' => DeliveryJob::DELIVERED,
            'pickup_name' => 'Shop', 'fee' => '20.00', 'delivered_at' => now(),
        ]);
        $finance = User::factory()->admin(AdminRole::Finance)->create();

        // Not refunded: nothing to pay by hand (the fee comes when the order completes).
        $this->actingAs($finance)->post(route('admin.payouts.rider-fee', $job))->assertSessionHasErrors('general');

        $order->forceFill(['status' => OrderStatus::Refunded])->save();
        $this->actingAs($finance)->get(route('admin.riders.index'))->assertOk()->assertSee('Pay fee');
        $this->actingAs(User::factory()->admin(AdminRole::Support)->create())->get(route('admin.riders.index'))->assertOk()->assertDontSee('Pay fee');

        $this->actingAs($finance)->post(route('admin.payouts.rider-fee', $job))->assertSessionHas('status', "Paid Le 20.00 to Ibrahim Rider's balance.");
        $this->actingAs($finance)->post(route('admin.payouts.rider-fee', $job))->assertSessionHasErrors(['general' => 'The rider was already paid for this delivery.']);

        $this->assertSame('20.00', app(LedgerService::class)->riderAvailable($rider));
        $this->assertSame(LedgerType::DeliveryFee, LedgerEntry::sole()->type);
        $this->actingAs($finance)->get(route('admin.riders.index'))->assertDontSee('Pay fee');
    }
}
