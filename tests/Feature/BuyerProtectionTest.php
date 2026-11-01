<?php

namespace Tests\Feature;

use App\Enums\LedgerType;
use App\Enums\Operator;
use App\Models\Coupon;
use App\Models\Dispute;
use App\Models\LedgerEntry;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\BuyerProtectionService;
use App\Services\CheckoutService;
use App\Services\LedgerService;
use App\Support\RewardSettings;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Price protection, late-shipping coupons and the genuine guarantee: the buyer gets a coupon, the seller pays. */
class BuyerProtectionTest extends TestCase
{
    use Marketplace;

    private function lowerPrice($product, string $price): void
    {
        $this->actingAs($product->seller->user)->put(route('seller.products.update', $product), [
            'title' => $product->title, 'price' => $price, 'stock' => $product->stock, 'unit' => $product->unit,
            'min_order_quantity' => 1, 'condition' => 'new', 'status' => 'active',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_price_drop_soon_after_buying_gives_the_buyer_the_difference(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller, ['price' => '100.00', 'stock' => 20]);
        $recent = $this->paidOrder($product, null, 2);
        $old = $this->paidOrder($product, null, 1);
        $old->forceFill(['paid_at' => now()->subDays(8)])->save(); // outside the 7 days
        $refunded = $this->paidOrder($product, null, 1);
        $refunded->forceFill(['status' => 'refunded'])->save();

        $this->lowerPrice($product, '85');

        $coupon = Coupon::sole();
        $this->assertSame($recent->buyer_id, $coupon->user_id);
        $this->assertSame('30.00', $coupon->amount); // (100 - 85) x 2
        $this->assertSame('price_protection', $coupon->source);
        $this->assertSame('-30.00', LedgerEntry::where('type', LedgerType::PriceProtection)->sole()->amount);

        // A second drop gives only the extra difference; raising the price gives nothing back.
        $this->lowerPrice($product, '80');
        $this->assertSame(['30.00', '10.00'], Coupon::orderBy('id')->pluck('amount')->all());
        $this->lowerPrice($product, '120');
        $this->lowerPrice($product, '80');
        $this->assertSame(2, Coupon::count());
        $this->assertSame('-40.00', app(LedgerService::class)->available($seller));
    }

    public function test_price_protection_follows_the_option_that_was_bought(): void
    {
        $product = $this->makeProduct(null, ['price' => '100.00', 'stock' => 10]);
        $large = ProductVariant::factory()->create(['product_id' => $product->id, 'name' => 'Large', 'price' => '150.00', 'stock' => 5]);
        $buyer = $this->buyer();
        $checkout = app(CheckoutService::class)->place($buyer, [['product_id' => $product->id, 'variant_id' => $large->id, 'quantity' => 1]],
            ['name' => $buyer->name, 'phone' => $buyer->phone, 'district' => 'Western Area Urban', 'address' => '12 Test Street'],
            [$product->seller_id => 'pickup'], Operator::Orange, $buyer->phone);
        $order = $this->pay($checkout)->orders()->sole();

        $product->forceFill(['price' => '90.00'])->save(); // the main price does not matter for an option with its own price
        $large->forceFill(['price' => '140.00'])->save();
        app(BuyerProtectionService::class)->onPriceChange($product->fresh());

        $this->assertSame('10.00', Coupon::where('user_id', $order->buyer_id)->sole()->amount);
    }

    public function test_an_order_not_shipped_in_time_gets_one_coupon_paid_by_the_seller(): void
    {
        $product = $this->makeProduct(null, ['price' => '400.00']);
        $late = $this->paidOrder($product);
        $this->orders()->accept($late);
        $onTime = $this->paidOrder($product);
        $this->orders()->accept($onTime);
        $late->forceFill(['accepted_at' => now()->subHours(49)])->save();

        $this->artisan('wibuy:late-shipping')->assertSuccessful();
        $this->artisan('wibuy:late-shipping')->assertSuccessful(); // only once

        $coupon = Coupon::sole();
        $this->assertSame($late->buyer_id, $coupon->user_id);
        $this->assertSame('20.00', $coupon->amount); // 5% of 400
        $this->assertSame('20.00', $late->fresh()->late_compensation);
        $this->assertSame('-20.00', LedgerEntry::where('type', LedgerType::LateShipping)->sole()->amount);

        // Capped by the admin setting.
        RewardSettings::put('late_ship_coupon_max', '10.00');
        $big = $this->paidOrder($this->makeProduct(null, ['price' => '5000.00']));
        $this->orders()->accept($big);
        $big->forceFill(['accepted_at' => now()->subDays(3)])->save();
        $this->artisan('wibuy:late-shipping');
        $this->assertSame('10.00', Coupon::where('user_id', $big->buyer_id)->sole()->amount);
    }

    public function test_fake_goods_sold_as_genuine_are_refunded_plus_a_coupon_from_the_seller(): void
    {
        $product = $this->makeProduct(null, ['price' => '300.00', 'genuine_guarantee' => true]);
        $order = $this->shippedOrder($product);
        $this->assertTrue($order->items()->sole()->genuine_guarantee);
        $this->get(route('products.show', $product))->assertSee('Genuine guarantee');

        $this->actingAs($order->buyer)->post(route('disputes.store', $order), ['reason' => 'fake', 'description' => 'The phone is a copy, not the real brand.'])->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->admin()->create())->post(route('admin.disputes.resolve', Dispute::sole()), ['decision' => 'buyer', 'note' => 'Fake'])->assertSessionHasNoErrors();

        $this->assertSame('300.00', Coupon::where('source', 'genuine_guarantee')->sole()->amount); // 100% of the item
        $this->assertSame('-300.00', LedgerEntry::where('type', LedgerType::GenuineGuarantee)->sole()->amount);
    }

    public function test_no_extra_coupon_without_the_guarantee_or_for_other_problems(): void
    {
        $plain = $this->shippedOrder($this->makeProduct(null, ['price' => '300.00']));
        $this->actingAs($plain->buyer)->post(route('disputes.store', $plain), ['reason' => 'fake', 'description' => 'This is not the real brand at all.']);
        $guaranteed = $this->shippedOrder($this->makeProduct(null, ['price' => '300.00', 'genuine_guarantee' => true]));
        $this->actingAs($guaranteed->buyer)->post(route('disputes.store', $guaranteed), ['reason' => 'damaged', 'description' => 'The box arrived broken and wet.']);
        $admin = User::factory()->admin()->create();

        foreach (Dispute::all() as $dispute) {
            $this->actingAs($admin)->post(route('admin.disputes.resolve', $dispute), ['decision' => 'buyer']);
        }

        $this->assertSame(0, Coupon::where('source', 'genuine_guarantee')->count());
    }
}
