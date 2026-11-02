<?php

namespace Tests\Feature\Rewards;

use App\Models\Coupon;
use App\Models\User;
use App\Support\RewardSettings;
use Tests\TestCase;

/** New-user welcome pack, the bonus coupon unlocked by a friend's sign-up, and the app coupon. */
class WelcomeAndReferralTest extends TestCase
{
    private function appLogin(string $phone, array $extra = [], ?string $device = null): array
    {
        $this->postJson('/api/v1/auth/code', ['phone' => $phone])->assertOk();
        $normalized = '232'.substr(preg_replace('/\D/', '', $phone), -8);

        return $this->withHeaders($device ? ['X-Device-Id' => $device] : [])
            ->postJson('/api/v1/auth/verify', ['phone' => $phone, 'code' => $this->sms->lastCodeTo($normalized), 'name' => 'New Person', 'district' => 'Bo', 'area' => 'Bo town'] + $extra)
            ->assertOk()->json();
    }

    public function test_a_new_app_user_gets_the_welcome_pack_with_a_locked_bonus_and_the_app_coupon(): void
    {
        $this->appLogin('076 300 001', [], 'phone-a');
        $user = User::where('phone', '23276300001')->firstOrFail();

        $this->assertSame(2, $user->coupons()->where('source', 'welcome')->where('status', Coupon::AVAILABLE)->count());
        $bonus = $user->coupons()->where('source', 'welcome_bonus')->firstOrFail();
        $this->assertSame(Coupon::LOCKED, $bonus->status);
        $this->assertStringContainsString('friend', $bonus->unlock_hint);
        $this->assertTrue($user->coupons()->where('source', 'app_install')->firstOrFail()->app_only);
        $this->assertNotNull($user->referral_code);
        $this->assertSame('app', $user->signup_channel);
    }

    public function test_logging_in_again_does_not_give_a_second_pack(): void
    {
        $this->appLogin('076 300 002');
        $this->appLogin('076 300 002');

        $this->assertSame(4, User::where('phone', '23276300002')->firstOrFail()->coupons()->count());
    }

    public function test_a_friend_signing_up_from_the_link_unlocks_the_bonus_and_gives_points(): void
    {
        $this->appLogin('076 300 003', [], 'phone-sharer');
        $sharer = User::where('phone', '23276300003')->firstOrFail();

        $this->appLogin('076 300 004', ['referral_code' => strtolower($sharer->referral_code)], 'phone-friend');
        $friend = User::where('phone', '23276300004')->firstOrFail();

        $this->assertSame($sharer->id, $friend->referred_by_id);
        $this->assertSame(Coupon::AVAILABLE, $sharer->coupons()->where('source', 'welcome_bonus')->firstOrFail()->status);
        $this->assertSame(RewardSettings::int('referral_points'), $sharer->fresh()->points_balance);
    }

    public function test_a_second_account_on_the_same_phone_does_not_count_as_a_referral(): void
    {
        $this->appLogin('076 300 005', [], 'same-phone');
        $sharer = User::where('phone', '23276300005')->firstOrFail();

        $this->appLogin('076 300 006', ['referral_code' => $sharer->referral_code], 'same-phone');

        $this->assertNull(User::where('phone', '23276300006')->firstOrFail()->referred_by_id);
        $this->assertSame(Coupon::LOCKED, $sharer->coupons()->where('source', 'welcome_bonus')->firstOrFail()->status);
        $this->assertSame(0, $sharer->fresh()->points_balance);
    }

    public function test_website_sign_ups_get_the_pack_and_the_referral_from_a_shared_link(): void
    {
        $sharer = User::factory()->create(['referral_code' => 'ABCDEFG']);

        $this->get('/?ref=abcdefg')->assertOk();
        $this->post(route('login.send'), ['phone' => '076 300 007']);
        $this->post(route('login.confirm'), ['code' => $this->sms->lastCodeTo('23276300007'), 'name' => 'Web Friend', 'district' => 'Bo', 'area' => 'Bo town'])->assertRedirect();

        $friend = User::where('phone', '23276300007')->firstOrFail();
        $this->assertSame($sharer->id, $friend->referred_by_id);
        $this->assertSame(2, $friend->coupons()->where('source', 'welcome')->count());
        $this->assertSame(0, $friend->coupons()->where('source', 'app_install')->count(), 'the app coupon is only for app logins');
    }
}
