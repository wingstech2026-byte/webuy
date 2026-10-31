<?php

namespace Tests\Feature\Rewards;

use App\Models\FarmMission;
use App\Models\FarmReward;
use App\Models\PriceCutCampaign;
use App\Models\SpinPrize;
use App\Models\User;
use App\Support\RewardSettings;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Admin screens for every reward and limit, seller promotions, and the app install link. */
class AdminRewardsTest extends TestCase
{
    use Marketplace;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_every_rewards_page_loads_for_admins_only(): void
    {
        $routes = ['admin.rewards.index', 'admin.rewards.settings', 'admin.rewards.spin', 'admin.rewards.deals', 'admin.rewards.cuts', 'admin.rewards.farm', 'admin.rewards.trials'];

        foreach ($routes as $route) {
            $this->actingAs($this->admin())->get(route($route))->assertOk();
            $this->actingAs($this->buyer())->get(route($route))->assertForbidden();
        }
    }

    public function test_admins_change_rewards_and_limits_and_they_apply(): void
    {
        $form = [];
        foreach (RewardSettings::FIELDS as $key => [, , $type, $default]) {
            $form[$key] = $type === 'bool' ? '1' : $default;
        }
        $form['check_in_points'] = 40;
        $form['spin_free_per_day'] = 2;

        $this->actingAs($this->admin())->put(route('admin.rewards.settings.update'), $form)->assertRedirect()->assertSessionHas('status');

        $this->assertSame(40, RewardSettings::int('check_in_points'));
        Sanctum::actingAs($this->buyer());
        $this->postJson('/api/v1/rewards/check-in')->assertJsonPath('points_earned', 40);
        $this->getJson('/api/v1/spin')->assertJsonPath('spins.left', 2);
    }

    public function test_admins_edit_spin_odds_farm_missions_and_prizes(): void
    {
        $admin = $this->admin();
        $prize = SpinPrize::first();

        $this->actingAs($admin)->put(route('admin.rewards.spin.update', $prize), ['label' => 'Big points', 'type' => 'points', 'points' => 99, 'weight' => 5, 'is_active' => '1'])->assertSessionHas('status');
        $this->assertSame(99, $prize->fresh()->points);

        $mission = FarmMission::where('key', 'order')->first();
        $this->put(route('admin.rewards.farm.mission', $mission), ['label' => 'Order', 'water' => 50, 'daily_limit' => 1])->assertSessionHas('status');
        $this->assertSame(50, $mission->fresh()->water);
        $this->assertFalse($mission->fresh()->is_active, 'unticked = off');

        $this->post(route('admin.rewards.farm.reward'), ['plant' => 'rice', 'label' => 'Le 30 coupon', 'type' => 'coupon', 'coupon_amount' => '30', 'cost' => '30'])->assertSessionHas('status');
        $this->assertSame('Le 30 coupon', FarmReward::for('rice')->label);

        $this->get(route('admin.rewards.farm'))->assertSee('Le 30 coupon');
    }

    public function test_admins_and_sellers_add_price_cuts_with_a_floor_below_the_price(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller, ['price' => '100.00']);
        $form = ['product' => $product->slug, 'floor_price' => '80', 'hours' => 24, 'min_cut' => '2', 'max_cut' => '5'];

        $this->actingAs($this->admin())->post(route('admin.rewards.cuts.store'), ['floor_price' => '150'] + $form)->assertSessionHasErrors('floor_price');
        $this->post(route('admin.rewards.cuts.store'), $form)->assertSessionHas('status');

        $this->actingAs($seller->user)->post(route('seller.promotions.cuts.store'), $form)->assertSessionHas('status');
        $this->assertSame(2, PriceCutCampaign::count());

        $other = $this->makeProduct();
        $this->post(route('seller.promotions.cuts.store'), ['product' => $other->slug] + $form)->assertSessionHasErrors('product');
        $this->get(route('seller.promotions'))->assertOk()->assertSee('Cut the price');
    }

    public function test_the_app_banner_remembers_the_product_for_after_install(): void
    {
        $product = $this->makeProduct();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 14)'])->get(route('products.show', $product))
            ->assertSee('off your first order in the WiBuy app');

        $this->get(route('app.get', ['path' => '/products/'.$product->slug]))
            ->assertRedirectContains('play.google.com')->assertRedirectContains('referrer=');

        $this->getJson('/api/v1/app/deferred-link')->assertJson(['path' => '/products/'.$product->slug]);
        $this->getJson('/api/v1/app/deferred-link')->assertJson(['path' => null], 'only once');
    }

    public function test_the_rewards_hub_brings_everything_together(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/rewards')->assertOk()->assertJsonStructure([
            'points', 'check_in' => ['done_today', 'streak'], 'spins' => ['left'], 'farm', 'farm_missions', 'price_cuts', 'coupons', 'locked_coupons', 'pass', 'referral_code',
        ]);
    }
}
