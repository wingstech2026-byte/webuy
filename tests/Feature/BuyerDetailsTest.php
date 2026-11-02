<?php

namespace Tests\Feature;

use App\Enums\Operator;
use App\Exceptions\MarketplaceException;
use App\Models\User;
use App\Services\CheckoutService;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Every buyer gives their name and where they live (the phone is checked by SMS) before buying. */
class BuyerDetailsTest extends TestCase
{
    use Marketplace;

    public function test_a_buyer_without_a_location_adds_it_and_goes_back_to_checkout(): void
    {
        $product = $this->makeProduct();
        $buyer = User::factory()->create(['district' => null, 'area' => null]);
        $checkout = route('checkout.show', ['product' => $product->slug]);

        $this->actingAs($buyer)->get($checkout)->assertRedirect(route('account.details'));
        $this->actingAs($buyer)->get(route('account.details'))->assertOk()->assertSee($buyer->display_phone)->assertSee('Town or area');

        $this->actingAs($buyer)->post(route('account.details.update'), ['name' => 'Fatmata Sesay', 'district' => 'Moon', 'area' => ''])
            ->assertSessionHasErrors(['district', 'area']);
        $this->actingAs($buyer)->post(route('account.details.update'), ['name' => 'Fatmata Sesay', 'district' => 'Bo', 'area' => 'Bo town'])
            ->assertRedirect($checkout);

        $this->assertSame(['Fatmata Sesay', 'Bo', 'Bo town'], [$buyer->fresh()->name, $buyer->fresh()->district, $buyer->fresh()->area]);
        $this->actingAs($buyer->fresh())->get($checkout)->assertOk();
    }

    public function test_no_purchase_without_name_and_location_from_any_channel(): void
    {
        $buyer = User::factory()->create(['area' => null]);
        $product = $this->makeProduct();

        $this->expectException(MarketplaceException::class);
        $this->expectExceptionMessage('Please add your full name and where you live (district and town) under My details before buying.');

        app(CheckoutService::class)->place($buyer, [['product_id' => $product->id, 'quantity' => 1]],
            ['name' => 'X', 'phone' => $buyer->phone], [$product->seller_id => 'pickup'], Operator::Orange, $buyer->phone);
    }

    public function test_the_sign_up_form_asks_for_name_and_location(): void
    {
        $this->post(route('login.send'), ['phone' => '076 888 111']);
        $this->get(route('login.verify'))->assertOk()->assertSee('Your full name')->assertSee('Your district')->assertSee('Town or area');
    }
}
