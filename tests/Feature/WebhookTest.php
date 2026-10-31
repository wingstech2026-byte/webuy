<?php

namespace Tests\Feature;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Jobs\InitiatePaymentJob;
use App\Models\Order;
use App\Models\User;
use App\Payments\PawaPayGateway;
use App\Payments\PaymentGateway;
use App\Services\LedgerService;
use App\Services\PaymentService;
use App\Services\PayoutService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use Marketplace;

    // ---------------------------------------------------------- with the fake gateway

    public function test_the_webhook_completes_a_payment_after_refetching_the_status(): void
    {
        $checkout = $this->placeOrder($this->buyer(), [[$this->makeProduct(), 1]]);
        $deposit = $this->depositOf($checkout);
        $this->gatewayReports($deposit); // the gateway (not the body) says completed

        $this->postJson('/webhooks/pawapay', ['depositId' => $deposit->provider_reference])
            ->assertOk()->assertJson(['received' => true]);

        $this->assertSame(CheckoutStatus::Paid, $checkout->fresh()->status);
        $this->assertSame(OrderStatus::Paid, Order::sole()->status);
    }

    public function test_the_webhook_never_trusts_its_body(): void
    {
        $checkout = $this->placeOrder($this->buyer(), [[$this->makeProduct(), 1]]);
        $deposit = $this->depositOf($checkout);
        $this->sms->reset();

        $this->postJson('/webhooks/pawapay', ['depositId' => $deposit->provider_reference, 'status' => 'COMPLETED', 'amount' => '100.00'])->assertOk();

        $this->assertSame(CheckoutStatus::PendingPayment, $checkout->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $deposit->fresh()->status);
        $this->assertSame([], $this->sms->sent);
    }

    public function test_receiving_the_same_callback_many_times_is_harmless(): void
    {
        $product = $this->makeProduct(null, ['stock' => 10]);
        $checkout = $this->placeOrder($this->buyer(), [[$product, 3]]);
        $deposit = $this->depositOf($checkout);
        $this->gatewayReports($deposit);

        $this->postJson('/webhooks/pawapay', ['depositId' => $deposit->provider_reference])->assertOk();
        $paidAt = Order::sole()->paid_at;
        $smsCount = count($this->sms->sent);

        $this->travel(5)->minutes();
        foreach (range(1, 3) as $i) {
            $this->postJson('/webhooks/pawapay', ['depositId' => $deposit->provider_reference])->assertOk();
        }

        $order = Order::sole();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertEquals($paidAt, $order->paid_at);
        $this->assertSame(7, $product->fresh()->stock, 'stock taken once');
        $this->assertCount($smsCount, $this->sms->sent, 'no duplicate texts');
        $this->assertSame(1, $checkout->payments()->count());
    }

    public function test_a_failed_deposit_callback_cancels_and_releases_stock_once(): void
    {
        $product = $this->makeProduct(null, ['stock' => 10]);
        $checkout = $this->placeOrder($this->buyer(), [[$product, 4]]);
        $deposit = $this->depositOf($checkout);
        $this->gatewayReports($deposit, 'FAILED');

        $this->postJson('/webhooks/pawapay', ['depositId' => $deposit->provider_reference])->assertOk();
        $this->postJson('/webhooks/pawapay', ['depositId' => $deposit->provider_reference])->assertOk();

        $this->assertSame(CheckoutStatus::Cancelled, $checkout->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_unknown_or_malformed_callbacks_get_a_plain_200(): void
    {
        $checkout = $this->placeOrder($this->buyer(), [[$this->makeProduct(), 1]]);

        foreach ([['depositId' => (string) Str::uuid()], ['depositId' => 'nope'], [], ['payoutId' => ['x']]] as $body) {
            $this->postJson('/webhooks/pawapay', $body)->assertOk();
        }

        $this->assertSame(CheckoutStatus::PendingPayment, $checkout->fresh()->status);
    }

    public function test_the_sync_job_catches_payments_whose_webhook_never_came(): void
    {
        $checkout = $this->placeOrder($this->buyer(), [[$this->makeProduct(), 1]]);
        $this->gatewayReports($this->depositOf($checkout));

        $this->travel(1)->minutes();
        $this->artisan('wibuy:sync-payments');
        $this->assertSame(CheckoutStatus::PendingPayment, $checkout->fresh()->status, 'too young to re-check');

        $this->travel(2)->minutes();
        $this->artisan('wibuy:sync-payments');
        $this->assertSame(CheckoutStatus::Paid, $checkout->fresh()->status);
    }

    public function test_all_jobs_are_scheduled_every_five_minutes(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        foreach (['wibuy:sync-payments', 'wibuy:cancel-unpaid', 'wibuy:refund-unaccepted', 'wibuy:complete-orders', 'wibuy:rewards-tick'] as $command) {
            $this->assertMatchesRegularExpression('#\*/5 \*\s+\* \* \*\s+php artisan '.preg_quote($command, '#').'\b#', $output);
        }
    }

    // --------------------------------------------------------- with the pawaPay driver

    private function usePawaPay(): void
    {
        config([
            'payments.gateway' => 'pawapay',
            'payments.pawapay.base_url' => 'https://api.sandbox.pawapay.io',
            'payments.pawapay.token' => 'secret-token',
        ]);
    }

    public function test_pawapay_receives_a_correct_deposit_request(): void
    {
        $this->usePawaPay();
        $this->assertInstanceOf(PawaPayGateway::class, app(PaymentGateway::class));
        Http::fake(['*/deposits' => Http::response(['status' => 'ACCEPTED'])]);

        $checkout = $this->placeOrder($this->buyer(), [[$this->makeProduct(null, ['price' => '450.00']), 2]]);
        $deposit = $this->depositOf($checkout);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.sandbox.pawapay.io/deposits'
            && $r->hasHeader('Authorization', 'Bearer secret-token')
            && $r['depositId'] === $deposit->provider_reference
            && $r['amount'] === '900.00'
            && $r['currency'] === 'SLE'
            && $r['correspondent'] === 'ORANGE_SLE'
            && $r['payer'] === ['type' => 'MSISDN', 'address' => ['value' => $deposit->phone]]);

        $this->assertTrue(Str::isUuid($deposit->provider_reference));
        $this->assertSame(PaymentStatus::Pending, $deposit->fresh()->status);
    }

    public function test_pawapay_webhook_refetches_and_is_idempotent(): void
    {
        $this->usePawaPay();
        Http::fake(['*/deposits' => Http::response(['status' => 'ACCEPTED'])]);
        $checkout = $this->placeOrder($this->buyer(), [[$this->makeProduct(), 1]]);
        $deposit = $this->depositOf($checkout);

        Http::fake(['*/deposits/'.$deposit->provider_reference => Http::response([['depositId' => $deposit->provider_reference, 'status' => 'COMPLETED']])]);

        // The body lies ("FAILED"); the re-fetched status wins.
        $this->postJson('/webhooks/pawapay', ['depositId' => $deposit->provider_reference, 'status' => 'FAILED'])->assertOk();
        $this->postJson('/webhooks/pawapay', ['depositId' => $deposit->provider_reference])->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_ends_with($r->url(), '/deposits/'.$deposit->provider_reference));
        $this->assertSame(CheckoutStatus::Paid, $checkout->fresh()->status);
    }

    public function test_pawapay_refunds_use_a_new_uuid_and_the_original_deposit(): void
    {
        $this->usePawaPay();
        Http::fake([
            '*/deposits' => Http::response(['status' => 'ACCEPTED']),
            '*/deposits/*' => Http::response([['status' => 'COMPLETED']]),
            '*/refunds' => Http::response(['status' => 'ACCEPTED']),
        ]);
        $product = $this->makeProduct(null, ['price' => '120.00']);
        $checkout = $this->placeOrder($this->buyer(), [[$product, 1]]);
        $deposit = $this->depositOf($checkout);
        $this->postJson('/webhooks/pawapay', ['depositId' => $deposit->provider_reference]);
        $order = Order::sole();

        $this->orders()->reject($order, 'Out of stock');

        $refund = $order->payments()->where('kind', 'refund')->sole();
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/refunds')
            && $r['refundId'] === $refund->provider_reference && $r['depositId'] === $deposit->provider_reference && $r['amount'] === '120.00');
        $this->assertNotSame($deposit->provider_reference, $refund->provider_reference);
        $this->assertSame(OrderStatus::Rejected, $order->fresh()->status, 'refunded only once pawaPay confirms');

        Http::fake(['*/refunds/*' => Http::response([['status' => 'COMPLETED']])]);
        $this->postJson('/webhooks/pawapay', ['refundId' => $refund->provider_reference])->assertOk();

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
    }

    public function test_pawapay_payouts_send_money_to_the_seller(): void
    {
        $this->usePawaPay();
        Http::fake(['*/payouts' => Http::response(['status' => 'ACCEPTED'])]);
        $seller = $this->makeSeller([], ['payout_phone' => '23230111222', 'payout_operator' => 'AFRIMONEY']);
        app(LedgerService::class)->adjust($seller, '500.00', 'Opening balance');

        $payout = app(PayoutService::class)->request($seller, '300.00');
        app(PayoutService::class)->approve($payout, User::factory()->admin()->create());

        $payment = $payout->payments()->sole();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/payouts')
            && $r['payoutId'] === $payment->provider_reference && $r['amount'] === '300.00'
            && $r['correspondent'] === 'AFRIMONEY_SLE' && $r['recipient']['address']['value'] === '23230111222');

        Http::fake(['*/payouts/*' => Http::response(['status' => 'FOUND', 'data' => ['status' => 'COMPLETED']])]);
        $this->postJson('/webhooks/pawapay', ['payoutId' => $payment->provider_reference])->assertOk();

        $this->assertSame(PayoutStatus::Paid, $payout->fresh()->status);
    }

    public function test_when_pawapay_is_down_the_job_retries_then_gives_up_cleanly(): void
    {
        $this->usePawaPay();
        Queue::fake();
        $product = $this->makeProduct(null, ['stock' => 3]);
        $checkout = $this->placeOrder($this->buyer(), [[$product, 3]]);
        $deposit = $this->depositOf($checkout);

        Http::fake(['*/deposits' => Http::response('Bad gateway', 502)]);
        $job = new InitiatePaymentJob($deposit->id);

        try {
            $job->handle(app(PaymentService::class));
            $this->fail('Expected the error to reach the queue for a retry');
        } catch (RequestException $e) {
            $this->assertSame(CheckoutStatus::PendingPayment, $checkout->fresh()->status);
        }

        $job->failed($e);

        $this->assertSame(CheckoutStatus::Cancelled, $checkout->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock);
    }
}
