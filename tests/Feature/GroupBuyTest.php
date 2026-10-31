<?php

namespace Tests\Feature;

use App\Enums\CheckoutStatus;
use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Checkout;
use App\Models\GroupBuy;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Group buying: each member pays their own order; the group counts only paid members; expiry refunds. */
class GroupBuyTest extends TestCase
{
    use Marketplace;

    private Product $product;

    private GroupBuyCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $seller = $this->makeSeller(['Western Area Urban' => '20.00']);
        $this->product = $this->makeProduct($seller, ['price' => '100.00', 'stock' => 10]);
        $this->campaign = GroupBuyCampaign::create([
            'product_id' => $this->product->id, 'seller_id' => $seller->id, 'group_price' => '90.00',
            'min_group_size' => 2, 'max_quantity_each' => 3, 'hours' => 24, 'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function order(int $quantity = 1): array
    {
        return ['quantity' => $quantity, 'method' => 'pickup', 'operator' => 'ORANGE', 'payer_phone' => '076 111 222'];
    }

    private function start(User $leader): string
    {
        Sanctum::actingAs($leader);

        return $this->postJson("/api/v1/group-buys/{$this->campaign->id}/start", $this->order())->assertCreated()
            ->assertJsonPath('group.paid_count', 0)
            ->assertJsonPath('checkout.total', '90.00')
            ->json('group.token');
    }

    private function join(User $member, string $token, array $headers = []): Order
    {
        Sanctum::actingAs($member);
        $this->postJson("/api/v1/groups/{$token}/join", $this->order(), $headers)->assertCreated();

        return GroupBuyParticipant::where('user_id', $member->id)->sole()->order;
    }

    private function orderOf(User $user): Order
    {
        return GroupBuyParticipant::where('user_id', $user->id)->sole()->order;
    }

    public function test_the_group_succeeds_only_when_enough_members_have_paid(): void
    {
        $aminata = $this->buyer(['name' => 'Aminata Kamara']);
        $token = $this->start($aminata);
        $this->pay($this->orderOf($aminata)->checkout);

        $first = $this->orderOf($aminata)->refresh();
        $this->assertSame(OrderStatus::Paid, $first->status);
        $this->assertNull($first->accept_by);
        $this->assertSame(GroupBuy::OPEN, GroupBuy::sole()->status);

        try {
            $this->orders()->accept($first);
            $this->fail('A group order must not be accepted before the group is complete.');
        } catch (MarketplaceException) {
        }

        $musa = $this->buyer(['name' => 'Musa Sesay']);
        $second = $this->join($musa, $token);
        $this->assertSame(1, GroupBuy::sole()->paid_count, 'An unpaid member is not counted.');

        $this->pay($second->checkout);

        $this->getJson("/api/v1/groups/{$token}")->assertOk()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.paid_count', 2)
            ->assertJsonPath('data.members', ['Aminata', 'Musa']);
        $this->get("/g/{$token}")->assertOk()->assertSee('2 of 2 paid');
        $this->assertNotNull($first->refresh()->accept_by);
        $this->orders()->accept($first);
        $this->assertSame(8, $this->product->fresh()->stock);
        $this->assertStringContainsString('is complete', implode("\n", $this->sms->messagesTo($musa->phone)));
    }

    public function test_an_expired_group_refunds_paid_members_cancels_unpaid_ones_and_returns_stock_once(): void
    {
        $leader = $this->buyer();
        $token = $this->start($leader);
        $this->pay($this->orderOf($leader)->checkout);
        $unpaid = $this->join($this->buyer(), $token);
        $this->assertSame(8, $this->product->fresh()->stock);

        $this->travel(25)->hours();
        $this->artisan('wibuy:expire-groups')->assertSuccessful();
        $this->artisan('wibuy:expire-groups')->assertSuccessful();

        $this->assertSame(GroupBuy::FAILED, GroupBuy::sole()->status);
        $this->assertSame(OrderStatus::Refunded, $this->orderOf($leader)->status);
        $this->assertSame(['90.00'], Payment::where('kind', 'refund')->pluck('amount')->all());
        $this->assertSame(CheckoutStatus::Cancelled, Checkout::find($unpaid->checkout_id)->status);
        $this->assertSame(['refunded', 'cancelled'], GroupBuyParticipant::orderBy('id')->pluck('status')->all());
        $this->assertSame(10, $this->product->fresh()->stock);
    }

    public function test_a_payment_approved_at_the_last_second_is_counted_before_the_group_closes(): void
    {
        $leader = $this->buyer();
        $token = $this->start($leader);
        $this->pay($this->orderOf($leader)->checkout);
        $late = $this->join($this->buyer(), $token);
        $this->gatewayReports($this->depositOf($late->checkout));

        $this->travel(25)->hours();
        $this->artisan('wibuy:expire-groups')->assertSuccessful();

        $this->assertSame(GroupBuy::SUCCEEDED, GroupBuy::sole()->status);
        $this->assertSame(0, Payment::where('kind', 'refund')->count());
    }

    public function test_join_rules_are_enforced(): void
    {
        $token = $this->start($this->buyer());

        Sanctum::actingAs($this->campaign->seller->user);
        $this->postJson("/api/v1/groups/{$token}/join", $this->order())->assertUnprocessable()
            ->assertJson(['message' => 'You cannot join a group for your own product.']);

        Sanctum::actingAs($this->buyer());
        $this->postJson("/api/v1/groups/{$token}/join", $this->order(4))->assertUnprocessable()
            ->assertJson(['message' => 'Each member can buy 1 to 3.']);

        $member = $this->buyer();
        $this->join($member, $token, ['X-Device-Id' => 'phone-1']);
        $this->postJson("/api/v1/groups/{$token}/join", $this->order())->assertUnprocessable();

        Sanctum::actingAs($this->buyer());
        $this->postJson("/api/v1/groups/{$token}/join", $this->order(), ['X-Device-Id' => 'phone-1'])->assertUnprocessable()
            ->assertJson(['message' => 'This phone is already in the group with another account.']);

        $this->campaign->update(['eligible_districts' => ['Bo']]);
        $this->postJson("/api/v1/groups/{$token}/join", ['method' => 'delivery', 'district' => 'Western Area Urban', 'address' => '1 Main Road'] + $this->order())
            ->assertUnprocessable();
    }

    public function test_a_seller_offer_must_be_a_real_saving_on_their_own_product(): void
    {
        $seller = $this->campaign->seller;
        $other = $this->makeProduct();
        Sanctum::actingAs($seller->user);
        $offer = ['product' => $this->product->slug, 'group_price' => '100.00', 'min_group_size' => 3, 'max_quantity_each' => 2, 'hours' => 12];

        $this->postJson('/api/v1/seller/group-offers', $offer)->assertUnprocessable()->assertJsonValidationErrors('group_price');
        $this->postJson('/api/v1/seller/group-offers', ['product' => $other->slug, 'group_price' => '1.00'] + $offer)
            ->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->postJson('/api/v1/seller/group-offers', ['group_price' => '85.50'] + $offer)->assertSuccessful();

        $this->assertSame('85.50', GroupBuyCampaign::latest('id')->first()->group_price);
    }
}
