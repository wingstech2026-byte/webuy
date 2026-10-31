<?php

namespace Tests\Feature;

use App\Models\Checkout;
use App\Models\GroupBuy;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\Product;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Starting and joining a group on the website, paying through the normal payment page. */
class GroupBuyWebsiteTest extends TestCase
{
    use Marketplace;

    private Product $product;

    private GroupBuyCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $seller = $this->makeSeller();
        $this->product = $this->makeProduct($seller, ['title' => 'Rice 25kg', 'price' => '100.00', 'stock' => 10]);
        $this->campaign = GroupBuyCampaign::create([
            'product_id' => $this->product->id, 'seller_id' => $seller->id, 'group_price' => '90.00',
            'min_group_size' => 2, 'max_quantity_each' => 3, 'hours' => 24, 'is_active' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function form(array $overrides = []): array
    {
        return $overrides + [
            'quantity' => 1, 'method' => 'pickup', 'recipient_name' => 'Aminata', 'recipient_phone' => '076 111 222',
            'operator' => 'ORANGE', 'payer_phone' => '076 111 222',
        ];
    }

    public function test_the_product_page_offers_the_group_and_a_buyer_starts_one_and_pays(): void
    {
        $this->get(route('products.show', $this->product))->assertOk()->assertSee('Buy together: Le 90.00 each')->assertSee('Log in to start a group');
        $this->get(route('group-buys.index'))->assertOk()->assertSee('Rice 25kg');

        $buyer = $this->buyer();
        $response = $this->actingAs($buyer)->post(route('group-buys.start', $this->campaign), $this->form(['quantity' => 2]));

        $checkout = Checkout::sole();
        $response->assertRedirect(route('checkout.status', $checkout));
        $this->assertSame('180.00', $checkout->total);
        $this->assertSame($buyer->id, GroupBuy::sole()->leader_id);

        $this->pay($checkout);
        $this->assertSame(1, GroupBuy::sole()->paid_count);
    }

    public function test_a_friend_joins_from_the_link_and_the_group_completes(): void
    {
        $this->actingAs($this->buyer())->post(route('group-buys.start', $this->campaign), $this->form());
        $this->pay(Checkout::sole());
        $group = GroupBuy::sole();

        $this->app['auth']->guard('web')->logout();
        $this->get(route('groups.show', $group))->assertOk()->assertSee('Log in to join');

        $friend = $this->buyer(['name' => 'Musa Sesay']);
        $this->actingAs($friend)->get(route('groups.show', $group))->assertOk()->assertSee('Join and pay Le 90.00 each');
        $this->actingAs($friend)->post(route('groups.join', $group), $this->form(['recipient_name' => 'Musa']))->assertRedirect();
        $this->pay(Checkout::findOrFail(GroupBuyParticipant::where('user_id', $friend->id)->sole()->checkout_id));

        $this->assertSame(GroupBuy::SUCCEEDED, $group->fresh()->status);
        $this->actingAs($friend)->get(route('groups.show', $group))->assertSee('2 of 2 paid');
    }

    public function test_website_join_rules_show_as_form_errors(): void
    {
        $this->post(route('group-buys.start', $this->campaign), $this->form())->assertRedirect(route('login'));

        $this->actingAs($this->buyer())->post(route('group-buys.start', $this->campaign), $this->form(['quantity' => 4]))
            ->assertSessionHasErrors('general');
        $this->actingAs($this->buyer())->post(route('group-buys.start', $this->campaign), $this->form(['method' => 'delivery']))
            ->assertSessionHasErrors(['district', 'address']);

        $this->assertSame(0, Checkout::count());
    }
}
