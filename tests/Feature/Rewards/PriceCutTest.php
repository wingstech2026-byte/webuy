<?php

namespace Tests\Feature\Rewards;

use App\Enums\Operator;
use App\Models\PriceCut;
use App\Models\PriceCutCampaign;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\DeviceService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** "Help me cut the price": friends cut until the floor; the buyer then gets a coupon for one unit. */
class PriceCutTest extends TestCase
{
    use Marketplace;

    private function campaign(array $attributes = []): PriceCutCampaign
    {
        return PriceCutCampaign::create($attributes + [
            'product_id' => $this->makeProduct(null, ['price' => '100.00'])->id,
            'floor_price' => '70.00', 'hours' => 24, 'min_cut' => '10.00', 'max_cut' => '10.00', 'is_active' => true,
        ]);
    }

    private function start(User $owner, PriceCutCampaign $campaign): string
    {
        Sanctum::actingAs($owner);

        return $this->postJson('/api/v1/price-cuts', ['campaign_id' => $campaign->id])
            ->assertCreated()->assertJsonPath('data.current_price', '100.00')->json('data.token');
    }

    private function cutAs(User $friend, string $token, string $device)
    {
        Sanctum::actingAs($friend);

        return $this->withHeaders(['X-Device-Id' => $device])->postJson("/api/v1/price-cuts/{$token}/cut");
    }

    public function test_friends_cut_the_price_to_the_floor_and_the_buyer_gets_a_coupon(): void
    {
        $owner = $this->buyer();
        $token = $this->start($owner, $campaign = $this->campaign());

        $this->cutAs($this->buyer(), $token, 'f1')->assertOk()->assertJsonPath('amount', '10.00')->assertJsonPath('data.current_price', '90.00');
        $this->cutAs($this->buyer(), $token, 'f2')->assertOk();
        $this->cutAs($this->buyer(), $token, 'f3')->assertOk()->assertJsonPath('data.status', 'reached')->assertJsonPath('data.progress_percent', 100);
        $this->cutAs($this->buyer(), $token, 'f4')->assertUnprocessable()->assertJsonPath('message', 'Thank you! This price is already at its lowest.');

        $cut = PriceCut::where('token', $token)->firstOrFail();
        $this->assertSame('70.00', (string) $cut->current_price);
        $coupon = $cut->coupon;
        $this->assertSame('30.00', (string) $coupon->amount);
        $this->assertSame($campaign->product_id, $coupon->product_id);

        $checkout = $this->pay($this->placeOrderWithCoupon($owner, $campaign, $coupon->id));
        $this->assertSame('70.00', (string) $checkout->total);
    }

    private function placeOrderWithCoupon(User $owner, PriceCutCampaign $campaign, int $couponId)
    {
        return app(CheckoutService::class)->place(
            $owner, [['product_id' => $campaign->product_id, 'quantity' => 1]],
            ['name' => 'O', 'phone' => $owner->phone], [$campaign->product->seller_id => 'pickup'],
            Operator::Orange, $owner->phone, $couponId, 'app',
        );
    }

    public function test_the_last_cut_never_goes_below_the_floor(): void
    {
        $token = $this->start($this->buyer(), $this->campaign(['min_cut' => '25.00', 'max_cut' => '25.00']));

        $this->cutAs($this->buyer(), $token, 'a')->assertOk();
        $this->cutAs($this->buyer(), $token, 'b')->assertOk()->assertJsonPath('amount', '5.00')->assertJsonPath('data.current_price', '70.00');
    }

    public function test_self_cuts_repeat_cuts_and_shared_devices_are_blocked(): void
    {
        $owner = $this->buyer();
        $campaign = $this->campaign();
        app(DeviceService::class)->record($owner, app(DeviceService::class)->hash('owner-phone'));
        $token = $this->start($owner, $campaign);

        $this->cutAs($owner, $token, 'owner-phone')->assertUnprocessable()->assertJsonPath('message', 'You cannot cut your own price. Share the link with friends.');

        $sockPuppet = $this->buyer();
        $this->cutAs($sockPuppet, $token, 'owner-phone')->assertUnprocessable()->assertJsonPath('message', 'Friends must cut from their own phone.');

        $friend = $this->buyer();
        $this->cutAs($friend, $token, 'friend-phone')->assertOk();
        $this->cutAs($friend, $token, 'friend-phone')->assertUnprocessable()->assertJsonPath('message', 'You already cut the price of this product. Each friend can cut once.');

        $friendsSecondAccount = $this->buyer();
        $this->cutAs($friendsSecondAccount, $token, 'friend-phone')->assertUnprocessable()->assertJsonPath('message', 'Only one account per phone can cut this price.');
    }

    public function test_one_cut_per_friend_per_product_even_across_buyers(): void
    {
        $campaign = $this->campaign();
        $friend = $this->buyer();
        $first = $this->start($this->buyer(), $campaign);
        $second = $this->start($this->buyer(), $campaign);

        $this->cutAs($friend, $first, 'fp')->assertOk();
        $this->cutAs($friend, $second, 'fp')->assertUnprocessable();
    }

    public function test_cuts_end_after_the_time_limit(): void
    {
        $token = $this->start($this->buyer(), $this->campaign(['hours' => 1]));
        $this->travel(61)->minutes();

        $this->cutAs($this->buyer(), $token, 'late')->assertUnprocessable()->assertJsonPath('message', 'This price cut has ended.');
        $this->artisan('wibuy:rewards-tick')->assertSuccessful();
        $this->assertSame('expired', PriceCut::where('token', $token)->value('status'));
    }

    public function test_friends_can_cut_on_the_website_from_the_whatsapp_link(): void
    {
        $token = $this->start($this->buyer(), $this->campaign());
        $friend = $this->buyer();

        $this->get(route('price-cuts.show', $token))->assertOk()->assertSee('cut the price');
        $this->actingAs($friend)->post(route('price-cuts.cut', $token))->assertSessionHas('status');
        $this->assertSame('90.00', (string) PriceCut::where('token', $token)->value('current_price'));
    }
}
