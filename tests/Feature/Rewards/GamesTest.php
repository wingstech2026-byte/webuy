<?php

namespace Tests\Feature\Rewards;

use App\Models\Farm;
use App\Models\FarmReward;
use App\Models\RewardClaim;
use App\Models\Share;
use App\Models\SpinPrize;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\FarmService;
use App\Support\RewardSettings;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Daily check-in, the spin wheel, sharing and WiBuy Farm. */
class GamesTest extends TestCase
{
    use Marketplace;

    public function test_check_in_gives_points_that_grow_with_the_streak_once_a_day(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/rewards/check-in')->assertOk()->assertJson(['points_earned' => 10, 'streak' => 1]);
        $this->postJson('/api/v1/rewards/check-in')->assertUnprocessable();

        $this->travel(1)->days();
        $this->postJson('/api/v1/rewards/check-in')->assertOk()->assertJson(['points_earned' => 15, 'streak' => 2, 'points' => 25]);

        $this->travel(2)->days();
        $this->postJson('/api/v1/rewards/check-in')->assertOk()->assertJson(['streak' => 1], 'a missed day restarts the streak');
    }

    public function test_one_free_spin_a_day_plus_one_per_successful_share_up_to_the_limit(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/spin')->assertOk()->assertJsonPath('spins.left', 1);
        $this->postJson('/api/v1/spin')->assertOk()->assertJsonStructure(['prize' => ['label'], 'points']);
        $this->postJson('/api/v1/spin')->assertUnprocessable();

        // Three friends open three shared links: only two extra spins (max 3 a day).
        foreach (range(1, 3) as $i) {
            $url = $this->postJson('/api/v1/shares', ['kind' => 'app'])->assertCreated()->json('url');
            $this->app['auth']->forgetGuards();
            $this->actingAs($this->buyer())->get($url)->assertRedirect();
            Sanctum::actingAs($user);
        }

        $this->getJson('/api/v1/spin')->assertJsonPath('spins.left', 2)->assertJsonPath('spins.from_shares', 2);
    }

    public function test_opening_your_own_share_does_not_count(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);
        $url = $this->postJson('/api/v1/shares', ['kind' => 'app'])->json('url');

        $this->actingAs($user)->get($url);

        $this->assertNull(Share::first()->rewarded_at);
    }

    public function test_spin_odds_are_shown_and_only_points_or_coupons_are_won(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);

        $prizes = $this->getJson('/api/v1/spin')->json('prizes');
        $this->assertEqualsWithDelta(100, array_sum(array_column($prizes, 'odds_percent')), 0.5);

        SpinPrize::query()->update(['is_active' => false]);
        SpinPrize::create(['label' => 'Le 5 coupon', 'type' => 'coupon', 'coupon_amount' => '5.00', 'weight' => 1, 'is_active' => true]);

        $this->postJson('/api/v1/spin')->assertOk()->assertJsonPath('prize.label', 'Le 5 coupon');
        $this->assertSame(1, $user->coupons()->where('source', 'spin')->count());
    }

    public function test_points_are_exchanged_for_a_coupon_never_cash(): void
    {
        $user = User::factory()->create(['points_balance' => 600]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/points/exchange')->assertCreated()->assertJsonPath('data.amount', '5.00');
        $this->assertSame(100, $user->fresh()->points_balance);
        $this->postJson('/api/v1/points/exchange')->assertUnprocessable();
    }

    public function test_missions_water_the_plant_up_to_their_daily_limit(): void
    {
        $user = $this->buyer();
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/farm/plant', ['plant' => 'rice'])->assertCreated();

        $this->postJson('/api/v1/rewards/check-in');
        $this->getJson('/api/v1/lightning-deals')->assertOk();
        $this->getJson('/api/v1/lightning-deals')->assertOk(); // once a day only

        $farm = $user->farm()->first();
        $this->assertSame(15, $farm->water, 'check-in 10 + deals 5');
        $this->getJson('/api/v1/farm')->assertJsonPath('missions.1.done_today', 1);
    }

    public function test_friends_water_each_others_plants_once_a_day_from_their_own_phone(): void
    {
        $owner = $this->buyer();
        $farm = Farm::create(['user_id' => $owner->id, 'code' => 'ownerfarm', 'plant' => 'mango', 'water' => 0, 'target' => 300]);
        $friend = $this->buyer();
        Farm::create(['user_id' => $friend->id, 'code' => 'friendfarm', 'plant' => 'rice', 'water' => 0, 'target' => 300]);
        Sanctum::actingAs($friend);

        $this->withHeaders(['X-Device-Id' => 'friend'])->postJson('/api/v1/farm/friends/ownerfarm/water')->assertOk()->assertJsonPath('data.water', 5);
        $this->withHeaders(['X-Device-Id' => 'friend'])->postJson('/api/v1/farm/friends/ownerfarm/water')->assertUnprocessable();
        $this->assertSame(5, $friend->farm()->first()->water, 'the helper gets water too');

        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/farm/friends/ownerfarm/water')->assertUnprocessable()->assertJsonPath('message', 'This is your own plant. Share the link with a friend.');
        $this->assertSame(5, $farm->fresh()->water);
    }

    public function test_a_grown_plant_gives_its_gift_with_the_next_paid_order(): void
    {
        $user = $this->buyer();
        Farm::create(['user_id' => $user->id, 'code' => 'nearly', 'plant' => 'rice', 'water' => RewardSettings::int('farm_target') - 10, 'target' => RewardSettings::int('farm_target')]);

        app(CheckInService::class)->checkIn($user);

        $claim = RewardClaim::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('gift', $claim->type);
        $this->assertSame('pending', $claim->status);
        $this->assertSame(1, $user->farm()->first()->harvests);
        $this->assertSame(0, $user->farm()->first()->water, 'a new plant starts');

        $order = $this->paidOrder(null, $user);
        $this->assertStringContainsString('1kg bag of rice', $order->fresh()->gift_note);
        $this->assertSame('attached', $claim->fresh()->status);
        $this->assertSame($order->id, $claim->fresh()->order_id);
    }

    public function test_a_coupon_prize_is_given_at_once(): void
    {
        $user = $this->buyer();
        Farm::create(['user_id' => $user->id, 'code' => 'mango1', 'plant' => 'mango', 'water' => 299, 'target' => 300]);

        app(FarmService::class)->mission($user, 'order');

        $this->assertSame('50.00', (string) $user->coupons()->where('source', 'farm')->firstOrFail()->amount);
        $this->assertSame('fulfilled', RewardClaim::where('user_id', $user->id)->value('status'));
        $this->assertSame('50.00', (string) FarmReward::for('mango')->cost);
    }
}
