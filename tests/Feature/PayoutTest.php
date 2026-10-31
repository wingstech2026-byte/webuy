<?php

namespace Tests\Feature;

use App\Enums\LedgerType;
use App\Enums\PayoutStatus;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\GatewayResult;
use App\Payments\PaymentGateway;
use App\Services\LedgerService;
use App\Services\PayoutService;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use Marketplace;

    /** A seller with a completed 1000.00 order (950.00 available after 5% commission). */
    private function sellerWithBalance()
    {
        $seller = $this->makeSeller();
        $order = $this->shippedOrder($this->makeProduct($seller, ['price' => '1000.00']));
        $this->orders()->complete($order);

        return $seller->fresh()->load('user');
    }

    public function test_a_seller_requests_a_payout_of_the_available_balance(): void
    {
        $seller = $this->sellerWithBalance();
        $ledger = app(LedgerService::class);
        $this->assertSame('950.00', $ledger->available($seller));

        $this->actingAs($seller->user)->post(route('seller.payouts.store'), ['amount' => '600'])->assertRedirect(route('seller.earnings'));

        $payout = Payout::sole();
        $this->assertSame(PayoutStatus::Requested, $payout->status);
        $this->assertSame('600.00', $payout->amount);
        $this->assertSame($seller->payout_phone, $payout->phone);
        $this->assertSame($seller->payout_operator, $payout->operator);

        // The money leaves the balance at once so it cannot be asked for twice.
        $this->assertSame('350.00', $ledger->available($seller));
        $this->assertSame('600.00', $ledger->inTransit($seller));

        $this->actingAs($seller->user)->post(route('seller.payouts.store'), ['amount' => '400'])
            ->assertSessionHasErrors(['general' => 'You only have Le 350.00 available.']);
    }

    public function test_payouts_have_a_minimum_and_need_a_balance(): void
    {
        $seller = $this->sellerWithBalance();
        Setting::put('min_payout', '200.00');

        $this->actingAs($seller->user)->post(route('seller.payouts.store'), ['amount' => '150'])
            ->assertSessionHasErrors(['general' => 'The smallest payout is Le 200.00.']);
        $this->actingAs($seller->user)->post(route('seller.payouts.store'), ['amount' => '5000'])->assertSessionHasErrors('general');
        $this->actingAs($seller->user)->post(route('seller.payouts.store'), ['amount' => 'lots'])->assertSessionHasErrors('amount');

        $this->assertSame(0, Payout::count());
    }

    public function test_admin_approves_and_the_money_is_sent_and_texted(): void
    {
        $seller = $this->sellerWithBalance();
        $payout = app(PayoutService::class)->request($seller, '950.00');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('admin.payouts.index'))->assertOk()->assertSee('Le 950.00')->assertSee($seller->shop_name);
        $this->actingAs($admin)->post(route('admin.payouts.approve', $payout))->assertRedirect();

        $payout->refresh();
        $this->assertSame(PayoutStatus::Paid, $payout->status); // fake gateway pays at once
        $this->assertSame($admin->id, $payout->reviewed_by);
        $this->assertSame('950.00', $payout->payments()->sole()->amount);
        $this->assertStringContainsString('We sent your payout of Le 950.00', $this->sms->lastTo($seller->user->phone));

        $ledger = app(LedgerService::class);
        $this->assertSame('0.00', $ledger->available($seller));
        $this->assertSame('950.00', $ledger->paidOut($seller));

        // Approving twice is refused.
        $this->actingAs($admin)->post(route('admin.payouts.approve', $payout))->assertSessionHasErrors('general');
        $this->assertSame(1, $payout->payments()->count());
    }

    public function test_a_rejected_payout_goes_back_on_the_balance(): void
    {
        $seller = $this->sellerWithBalance();
        $payout = app(PayoutService::class)->request($seller, '500.00');

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.payouts.reject', $payout), ['reason' => 'Wrong number'])->assertRedirect();

        $this->assertSame(PayoutStatus::Rejected, $payout->fresh()->status);
        $this->assertSame('950.00', app(LedgerService::class)->available($seller));
        $this->assertSame(
            [LedgerType::Sale, LedgerType::Payout, LedgerType::PayoutReversal],
            $seller->ledgerEntries()->orderBy('id')->get()->pluck('type')->all(),
        );
        $this->assertStringContainsString('Wrong number', $this->sms->lastTo($seller->user->phone));
    }

    public function test_a_failed_payout_goes_back_on_the_balance(): void
    {
        $seller = $this->sellerWithBalance();
        $payout = app(PayoutService::class)->request($seller, '500.00');
        $this->app->instance(PaymentGateway::class, new class extends FakeGateway
        {
            public function initiatePayout(Payment $payout): GatewayResult
            {
                return GatewayResult::failed('Recipient not found');
            }
        });

        app(PayoutService::class)->approve($payout, User::factory()->admin()->create());

        $this->assertSame(PayoutStatus::Failed, $payout->fresh()->status);
        $this->assertSame('950.00', app(LedgerService::class)->available($seller));
    }

    public function test_only_sellers_request_and_only_admins_approve(): void
    {
        $seller = $this->sellerWithBalance();
        $payout = app(PayoutService::class)->request($seller, '100.00');

        $this->actingAs($this->buyer())->post(route('seller.payouts.store'), ['amount' => '100'])->assertRedirect(route('sell.apply'));
        $this->actingAs($seller->user)->post(route('admin.payouts.approve', $payout))->assertForbidden();
        $this->assertSame(PayoutStatus::Requested, $payout->fresh()->status);
    }

    public function test_the_earnings_page_shows_pending_available_and_paid_out(): void
    {
        $seller = $this->sellerWithBalance();
        $this->paidOrder($this->makeProduct($seller, ['price' => '100.00'])); // 95.00 pending

        $this->actingAs($seller->user)->get(route('seller.earnings'))->assertOk()
            ->assertSee('Pending')->assertSee('Le 95.00')
            ->assertSee('Available')->assertSee('Le 950.00')
            ->assertSee('Request payout');
    }
}
