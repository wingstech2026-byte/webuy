<?php

namespace Tests\Feature\Business;

use App\Enums\Operator;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\BusinessProfile;
use App\Models\Coupon;
use App\Models\OrderItem;
use App\Models\PriceTier;
use App\Models\Rfq;
use App\Models\RfqQuote;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Business buyers, business-only prices, MOQ and lead time, RFQ quotes, and samples. */
class BusinessBuyingTest extends TestCase
{
    use Marketplace;

    private function verifiedBusiness(): User
    {
        $user = $this->buyer();
        BusinessProfile::create(['user_id' => $user->id, 'business_name' => 'Kamara Hardware', 'registration_no' => 'SL-123', 'status' => 'verified', 'verified_at' => now()]);

        return $user->fresh();
    }

    private function place(User $buyer, array $lines, array $options = [])
    {
        $product = $lines[0]['product'];

        return app(CheckoutService::class)->place($buyer, array_map(fn ($l) => ['product_id' => $l['product']->id] + collect($l)->except('product')->all(), $lines),
            ['name' => 'B', 'phone' => $buyer->phone, 'district' => 'Western Area Urban', 'address' => '1 Road'],
            [$product->seller_id => 'pickup'], Operator::Orange, $buyer->phone, null, 'app', $options);
    }

    public function test_a_buyer_registers_a_business_with_papers_and_an_admin_verifies_it(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $user = $this->buyer();

        $this->actingAs($user)->post(route('business.store'), [
            'business_name' => 'Kamara Hardware', 'registration_no' => 'SL-123',
            'documents' => [UploadedFile::fake()->image('cert.jpg')],
        ])->assertSessionHas('status');

        $profile = $user->businessProfile()->firstOrFail();
        $this->assertSame('pending', $profile->status);
        $this->assertSame(1, $profile->documents()->count());

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.business.index'))->assertOk()->assertSee('Kamara Hardware');
        $this->post(route('admin.business.verify', $profile))->assertSessionHas('status');
        $this->assertTrue($user->fresh()->isVerifiedBusiness());

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/v1/business')->assertJson(['status' => 'verified']);
    }

    public function test_business_only_prices_are_shown_and_charged_only_to_verified_businesses(): void
    {
        $product = $this->makeProduct(null, ['price' => '100.00', 'lead_time_days' => 5, 'stock' => 200]);
        PriceTier::create(['product_id' => $product->id, 'min_quantity' => 10, 'unit_price' => '90.00']);
        PriceTier::create(['product_id' => $product->id, 'min_quantity' => 50, 'unit_price' => '75.00', 'business_only' => true]);

        $this->getJson("/api/v1/products/{$product->slug}")->assertJsonCount(1, 'data.tiers')
            ->assertJsonPath('data.has_business_prices', true)->assertJsonPath('data.lead_time_days', 5);

        Sanctum::actingAs($business = $this->verifiedBusiness());
        $this->getJson("/api/v1/products/{$product->slug}")->assertJsonCount(2, 'data.tiers');

        $this->assertSame('3750.00', (string) $this->place($business, [['product' => $product, 'quantity' => 50]])->total);
        $this->assertSame('4500.00', (string) $this->place($this->buyer(), [['product' => $product, 'quantity' => 50]])->total);
    }

    public function test_the_minimum_order_quantity_is_enforced_in_the_cart_and_at_checkout(): void
    {
        $product = $this->makeProduct(null, ['min_order_quantity' => 10]);
        Sanctum::actingAs($buyer = $this->buyer());

        $this->postJson("/api/v1/cart/{$product->slug}", ['quantity' => 3])->assertUnprocessable();

        $this->expectException(MarketplaceException::class);
        $this->place($buyer, [['product' => $product, 'quantity' => 3]]);
    }

    public function test_quotes_lock_the_price_are_compared_side_by_side_and_one_becomes_an_order(): void
    {
        $buyer = $this->buyer();
        Sanctum::actingAs($buyer);
        $rfqId = $this->postJson('/api/v1/rfqs', ['title' => '50 bags of cement', 'quantity' => 50, 'unit' => 'bag', 'district' => 'Bo'])
            ->assertCreated()->json('data.id');

        $cheap = $this->makeSeller(['Bo' => '200.00']);
        $near = $this->makeSeller(['Bo' => '20.00']);
        $cheapProduct = $this->makeProduct($cheap, ['price' => '150.00', 'stock' => 100]);
        $nearProduct = $this->makeProduct($near, ['price' => '150.00', 'stock' => 100]);

        $this->actingAs($cheap->user)->get(route('seller.rfqs.index'))->assertOk()->assertSee('50 bags of cement');
        $this->post(route('seller.rfqs.quote', $rfqId), ['product_id' => $cheapProduct->id, 'unit_price' => '100', 'quantity' => 50, 'lead_time_days' => 3])->assertSessionHas('status');
        $this->post(route('seller.rfqs.quote', $rfqId), ['product_id' => $cheapProduct->id, 'unit_price' => '90', 'quantity' => 50, 'lead_time_days' => 3])->assertSessionHasErrors('general');
        $this->actingAs($near->user)->post(route('seller.rfqs.quote', $rfqId), ['product_id' => $nearProduct->id, 'unit_price' => '102', 'quantity' => 50, 'lead_time_days' => 1])->assertSessionHas('status');

        Sanctum::actingAs($buyer);
        $quotes = $this->getJson("/api/v1/rfqs/{$rfqId}")->assertOk()->json('data.quotes');
        $this->assertSame(['5120.00', '5200.00'], array_column($quotes, 'total'), 'cheapest total including delivery first');
        $this->assertSame($near->id, $quotes[0]['seller']['id']);

        $quote = RfqQuote::findOrFail($quotes[0]['id']);
        $this->postJson("/api/v1/quotes/{$quote->id}/accept", [
            'recipient_name' => 'Musa', 'recipient_phone' => '076 111 222', 'address' => 'Bo town', 'method' => 'delivery',
            'operator' => 'ORANGE', 'payer_phone' => '076 111 222',
        ])->assertCreated()->assertJsonPath('data.total', '5120.00');

        $order = $quote->fresh()->rfq->quotes()->where('status', 'accepted')->firstOrFail();
        $this->assertSame('102.00', (string) OrderItem::where('product_id', $nearProduct->id)->value('unit_price'));
        $this->assertSame('awarded', Rfq::find($rfqId)->status);
        $this->assertSame('declined', RfqQuote::where('seller_id', $cheap->id)->value('status'));
        $this->assertNotNull($order);
    }

    public function test_an_expired_quote_cannot_be_accepted(): void
    {
        $buyer = $this->buyer();
        $seller = $this->makeSeller();
        $rfq = Rfq::create(['buyer_id' => $buyer->id, 'title' => 'Rice', 'quantity' => 10, 'district' => 'Western Area Urban']);
        $quote = RfqQuote::create(['rfq_id' => $rfq->id, 'seller_id' => $seller->id, 'product_id' => $this->makeProduct($seller)->id, 'unit_price' => '80', 'quantity' => 10, 'valid_until' => now()->addDays(14)]);

        $this->travel(15)->days();
        Sanctum::actingAs($buyer);

        $this->postJson("/api/v1/quotes/{$quote->id}/accept", ['recipient_name' => 'A', 'recipient_phone' => '076111222', 'method' => 'pickup', 'operator' => 'ORANGE', 'payer_phone' => '076111222'])
            ->assertUnprocessable()->assertJsonPath('message', 'This quote has expired or the request is closed. Ask the seller for a new quote.');
    }

    public function test_a_paid_sample_skips_the_minimum_and_is_credited_on_the_bulk_order(): void
    {
        $product = $this->makeProduct(null, ['price' => '100.00', 'min_order_quantity' => 20, 'sample_price' => '120.00']);
        $buyer = $this->buyer();
        Sanctum::actingAs($buyer);

        $this->postJson('/api/v1/checkout/quote', ['product' => $product->slug, 'sample' => true, 'methods' => [$product->seller_id => 'pickup']])
            ->assertOk()->assertJsonPath('amount_due', '120.00');

        $checkout = $this->pay($this->place($buyer, [['product' => $product, 'quantity' => 1, 'unit_price' => '120.00', 'is_sample' => true]]));
        $order = $checkout->orders()->first();
        $this->assertTrue((bool) $order->items->first()->is_sample);

        $this->orders()->accept($order);
        $this->orders()->ship($order, 'x');
        $this->orders()->confirmDelivery($order->fresh(), $buyer);

        $credit = Coupon::where('user_id', $buyer->id)->where('source', 'sample')->firstOrFail();
        $this->assertSame('120.00', (string) $credit->amount);

        $bulk = app(CheckoutService::class)->place($buyer, [['product_id' => $product->id, 'quantity' => 20]],
            ['name' => 'B', 'phone' => $buyer->phone], [$product->seller_id => 'pickup'], Operator::Orange, $buyer->phone, $credit->id, 'app');
        $this->assertSame('1900.00', (string) $bulk->total, 'Le 2,000 minus the one-unit credit (capped at the unit price)');
        $this->assertSame(OrderStatus::PendingPayment, $bulk->orders()->first()->status);
    }
}
