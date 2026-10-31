<?php

namespace Tests\Feature\Rewards;

use App\Enums\CheckoutStatus;
use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Coupon;
use App\Models\FlashDeal;
use App\Models\LedgerEntry;
use App\Models\Membership;
use App\Services\CheckoutService;
use App\Services\CouponService;
use App\Services\PaymentService;
use App\Support\Money;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Coupons, WiBuy Pass free delivery and member prices, and lightning deal prices at checkout. */
class CouponCheckoutTest extends TestCase
{
    use Marketplace;

    private function coupon($user, array $attributes = []): Coupon
    {
        return app(CouponService::class)->issue($user, $attributes + ['title' => 'Test', 'source' => 'admin', 'amount' => '20.00']);
    }

    private function place($buyer, array $lines, ?Coupon $coupon = null, string $method = 'pickup', string $channel = 'web')
    {
        $methods = [];
        foreach ($lines as [$product]) {
            $methods[$product->seller_id] = $method;
        }

        return app(CheckoutService::class)->place(
            $buyer,
            array_map(fn ($l) => ['product_id' => $l[0]->id, 'quantity' => $l[1]], $lines),
            ['name' => 'B', 'phone' => $buyer->phone, 'district' => 'Western Area Urban', 'address' => '1 Road'],
            $methods, Operator::Orange, $buyer->phone, $coupon?->id, $channel,
        );
    }

    private function activePass($user): void
    {
        Membership::create(['user_id' => $user->id, 'price' => '25.00', 'status' => 'active', 'payer_phone' => $user->phone, 'operator' => 'ORANGE', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(29)]);
    }

    public function test_a_coupon_lowers_what_the_buyer_pays_but_not_what_the_seller_earns(): void
    {
        $product = $this->makeProduct(null, ['price' => '100.00']);
        $buyer = $this->buyer();
        $coupon = $this->coupon($buyer);

        $checkout = $this->place($buyer, [[$product, 2]], $coupon);

        $this->assertSame('180.00', (string) $checkout->total);
        $this->assertSame('20.00', (string) $checkout->discount);
        $this->assertSame('180.00', $this->depositOf($checkout)->amount);
        $this->assertSame(Coupon::RESERVED, $coupon->fresh()->status);

        $this->pay($checkout);
        $order = $checkout->orders()->first();
        $this->assertSame(Coupon::USED, $coupon->fresh()->status);
        $this->assertSame('200.00', (string) $order->total, 'the seller side keeps the full price');
        $this->assertSame('180.00', $order->amount_paid);

        $this->orders()->accept($order);
        $this->orders()->ship($order, 'On its way');
        $this->orders()->confirmDelivery($order->fresh(), $buyer);
        $this->assertSame('190.00', Money::from(LedgerEntry::where('seller_id', $product->seller_id)->sum('amount')), 'Le 200 minus 5% commission');
    }

    public function test_a_failed_payment_gives_the_coupon_back(): void
    {
        $buyer = $this->buyer();
        $coupon = $this->coupon($buyer);
        $checkout = $this->place($buyer, [[$this->makeProduct(), 1]], $coupon);

        $deposit = $this->depositOf($checkout);
        $this->gatewayReports($deposit, 'FAILED');
        app(PaymentService::class)->sync($deposit);

        $this->assertSame(CheckoutStatus::Cancelled, $checkout->fresh()->status);
        $this->assertTrue($coupon->fresh()->isUsable());
    }

    public function test_coupon_rules_are_checked(): void
    {
        $buyer = $this->buyer();
        $product = $this->makeProduct(null, ['price' => '30.00']);

        $tooSmall = $this->coupon($buyer, ['min_spend' => '100.00']);
        $appOnly = $this->coupon($buyer, ['app_only' => true]);
        $someoneElses = $this->coupon($this->buyer());

        foreach ([[$tooSmall, 'at least Le 100.00'], [$appOnly, 'only works in the WiBuy app'], [$someoneElses, 'cannot be used']] as [$coupon, $message]) {
            try {
                $this->place($buyer, [[$product, 1]], $coupon);
                $this->fail('Coupon should have been refused');
            } catch (MarketplaceException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame('10.00', (string) $this->place($buyer, [[$product, 1]], $appOnly, channel: 'app')->total);
    }

    public function test_a_product_coupon_covers_one_unit_and_can_make_an_order_free(): void
    {
        $buyer = $this->buyer();
        $product = $this->makeProduct(null, ['price' => '60.00']);
        $coupon = $this->coupon($buyer, ['amount' => '60.00', 'product_id' => $product->id]);

        $checkout = $this->place($buyer, [[$product, 1]], $coupon);

        $this->assertSame(CheckoutStatus::Paid, $checkout->status, 'nothing to pay by phone');
        $this->assertSame(0, $checkout->payments()->count());
        $this->assertSame(OrderStatus::Paid, $checkout->orders()->first()->status);
    }

    public function test_refunds_return_what_the_buyer_actually_paid(): void
    {
        $buyer = $this->buyer();
        $checkout = $this->pay($this->place($buyer, [[$this->makeProduct(null, ['price' => '100.00']), 1]], $this->coupon($buyer)));
        $order = $checkout->orders()->first();

        $this->orders()->reject($order, 'Out of stock');

        $refund = $order->payments()->where('kind', 'refund')->firstOrFail();
        $this->assertSame('80.00', (string) $refund->amount);
    }

    public function test_wibuy_pass_members_get_free_delivery_and_member_prices(): void
    {
        $seller = $this->makeSeller(['Western Area Urban' => '20.00']);
        $product = $this->makeProduct($seller, ['price' => '100.00', 'member_price' => '90.00']);
        $member = $this->buyer();
        $this->activePass($member);

        $checkout = $this->place($member, [[$product, 1]], null, 'delivery');

        $order = $checkout->orders()->first();
        $this->assertSame('90.00', (string) $order->items->first()->unit_price);
        $this->assertSame('20.00', (string) $order->delivery_fee, 'the seller still gets the delivery fee');
        $this->assertSame('20.00', (string) $order->discount);
        $this->assertSame('90.00', (string) $checkout->total);

        $other = $this->place($this->buyer(), [[$product, 1]], null, 'delivery');
        $this->assertSame('120.00', (string) $other->total);
    }

    public function test_a_live_lightning_deal_price_applies_up_to_its_limit_per_order(): void
    {
        $product = $this->makeProduct(null, ['price' => '100.00']);
        FlashDeal::create(['product_id' => $product->id, 'deal_price' => '70.00', 'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHour(), 'max_per_order' => 2]);

        $this->assertSame('140.00', (string) $this->place($this->buyer(), [[$product, 2]])->total);
        $this->assertSame('300.00', (string) $this->place($this->buyer(), [[$product, 3]])->total, 'over the limit: normal price');

        $this->travel(2)->hours();
        $this->assertSame('100.00', (string) $this->place($this->buyer(), [[$product, 1]])->total, 'deal ended');
    }

    public function test_the_app_quote_shows_discounts_and_usable_coupons(): void
    {
        $buyer = $this->buyer();
        $coupon = $this->coupon($buyer, ['amount' => '15.00']);
        $product = $this->makeProduct(null, ['price' => '50.00']);
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout/quote', ['product' => $product->slug, 'qty' => 1, 'coupon_id' => $coupon->id, 'methods' => [$product->seller_id => 'pickup']])
            ->assertOk()
            ->assertJson(['grand_total' => '50.00', 'coupon_discount' => '15.00', 'amount_due' => '35.00', 'coupon_error' => null])
            ->assertJsonPath('coupons.0.id', $coupon->id);
    }
}
