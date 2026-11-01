<?php

namespace Tests\Feature;

use App\Enums\LedgerType;
use App\Enums\Operator;
use App\Enums\PayoutStatus;
use App\Models\DeliveryJob;
use App\Models\LedgerEntry;
use App\Models\Order;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\PayoutService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Riders earn the delivery fee when the order completes, and withdraw it to mobile money. */
class RiderPayoutTest extends TestCase
{
    use Marketplace;

    private Order $order;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('min_payout', '10.00');
        $this->rider = User::factory()->create(['name' => 'Ibrahim Rider', 'is_rider' => true]);
        $this->order = $this->paidOrder($this->makeProduct(null, ['price' => '1000.00']), null, 1, 'delivery');
        $this->orders()->accept($this->order);
    }

    /** The rider delivers the order with the buyer's code. */
    private function deliverByRider(): DeliveryJob
    {
        $this->actingAs($this->order->seller->user)->post(route('seller.orders.rider', $this->order))->assertSessionHasNoErrors();
        $job = DeliveryJob::sole();
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$job, 'accept']));
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$job, 'picked-up']));
        preg_match('/your goods: (\d{4})/', $this->sms->lastTo($this->order->buyer->phone), $m);
        $this->actingAs($this->rider)->post(route('rider.jobs.act', [$job, 'deliver']), ['code' => $m[1]])->assertSessionHasNoErrors();

        return $job->fresh();
    }

    public function test_the_rider_earns_the_delivery_fee_when_the_order_completes_and_the_seller_does_not(): void
    {
        $job = $this->deliverByRider();
        $ledger = app(LedgerService::class);
        $this->assertSame('20.00', $job->fee);

        // Delivered but not confirmed: pending, not available yet.
        $this->assertSame(['pending' => '20.00', 'available' => '0.00'], array_intersect_key($ledger->riderSummary($this->rider), array_flip(['pending', 'available'])));

        $this->orders()->complete($this->order->fresh());
        $this->assertNull($ledger->creditRider($this->order->fresh())); // crediting again does nothing

        $this->assertSame('20.00', $ledger->riderAvailable($this->rider));
        $this->assertSame('0.00', $ledger->riderSummary($this->rider)['pending']);
        $this->assertSame(1, LedgerEntry::where('type', LedgerType::DeliveryFee)->count());
        // 1020.00 paid, 5% commission on the goods (50.00), the 20.00 fee went to the rider.
        $this->assertSame('950.00', $ledger->available($this->order->seller));

        $this->actingAs($this->rider)->get(route('rider.earnings'))->assertOk()->assertSee('Le 20.00')->assertSee('Delivery of order '.$this->order->number);
    }

    public function test_the_rider_withdraws_and_an_admin_sends_it(): void
    {
        $this->deliverByRider();
        $this->orders()->complete($this->order->fresh());

        $this->actingAs($this->rider)->post(route('rider.payouts.store'), ['amount' => '30', 'operator' => 'AFRIMONEY', 'phone' => '077 123 456'])
            ->assertSessionHasErrors(['general' => 'You only have Le 20.00 available.']);
        $this->actingAs($this->rider)->post(route('rider.payouts.store'), ['amount' => '20', 'operator' => 'AFRIMONEY', 'phone' => '077 123 456'])
            ->assertRedirect(route('rider.earnings'));

        $payout = Payout::sole();
        $this->assertSame($this->rider->id, $payout->rider_id);
        $this->assertNull($payout->seller_id);
        $this->assertSame('23277123456', $payout->phone);
        $this->assertSame(Operator::Afrimoney, $payout->operator);
        $this->assertSame('0.00', app(LedgerService::class)->riderAvailable($this->rider));

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('admin.payouts.index'))->assertOk()->assertSee('Ibrahim Rider (rider)');
        $this->actingAs($admin)->post(route('admin.payouts.approve', $payout))->assertRedirect();

        $this->assertSame(PayoutStatus::Paid, $payout->fresh()->status);
        $this->assertStringContainsString('We sent your payout of Le 20.00', $this->sms->lastTo($this->rider->phone));
        $this->assertSame('20.00', app(LedgerService::class)->riderSummary($this->rider)['paid_out']);
    }

    public function test_a_rejected_rider_payout_goes_back_and_former_riders_can_still_withdraw(): void
    {
        $this->deliverByRider();
        $this->orders()->complete($this->order->fresh());
        $payout = app(PayoutService::class)->requestForRider($this->rider, '20.00', Operator::Orange, '23276111222');

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.payouts.reject', $payout), ['reason' => 'Wrong number']);
        $this->assertSame('20.00', app(LedgerService::class)->riderAvailable($this->rider));
        $this->assertStringContainsString('Wrong number', $this->sms->lastTo($this->rider->phone));

        $this->rider->forceFill(['is_rider' => false])->save();
        $this->actingAs($this->rider)->get(route('rider.index'))->assertForbidden();
        $this->actingAs($this->rider)->get(route('rider.earnings'))->assertOk();

        $this->actingAs($this->buyer())->get(route('rider.earnings'))->assertForbidden();
    }

    public function test_the_app_shows_earnings_and_requests_a_payout(): void
    {
        $this->deliverByRider();
        $this->orders()->complete($this->order->fresh());
        Sanctum::actingAs($this->rider);

        $this->getJson(route('api.v1.rider.earnings'))->assertOk()
            ->assertJsonPath('money.available', '20.00')
            ->assertJsonPath('min_payout', '10.00')
            ->assertJsonPath('pay_to.phone', $this->rider->phone)
            ->assertJsonPath('entries.0.amount', '20.00');

        $this->postJson(route('api.v1.rider.payouts.store'), ['amount' => '5', 'operator' => 'ORANGE', 'phone' => '076111222'])
            ->assertStatus(422)->assertJsonPath('message', 'The smallest payout is Le 10.00.');
        $this->postJson(route('api.v1.rider.payouts.store'), ['amount' => '15', 'operator' => 'ORANGE', 'phone' => '076111222'])
            ->assertCreated()->assertJsonPath('data.amount', '15.00');

        $this->getJson(route('api.v1.rider.earnings'))->assertJsonPath('money.available', '5.00')
            ->assertJsonPath('pay_to.operator', 'ORANGE')->assertJsonPath('payouts.0.status', 'requested');
    }
}
