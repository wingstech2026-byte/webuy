<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Services\OtpService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Phone + SMS code login for the mobile app, which returns a Sanctum token. */
class AuthApiTest extends TestCase
{
    private const PHONE = '23276123456';

    private function codeFor(string $phone): string
    {
        return $this->sms->lastCodeTo($phone);
    }

    public function test_requesting_a_code_texts_it_and_says_whether_a_name_is_needed(): void
    {
        $this->postJson('/api/v1/auth/code', ['phone' => '076 123 456'])
            ->assertOk()
            ->assertJson(['phone' => self::PHONE, 'needs_name' => true, 'expires_in_minutes' => 10]);

        $this->assertNotNull($this->codeFor(self::PHONE));
    }

    public function test_an_invalid_phone_gets_a_readable_422(): void
    {
        $this->postJson('/api/v1/auth/code', ['phone' => '123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'Please enter a valid Sierra Leone phone number, like 076 123 456.']);
    }

    public function test_a_new_user_verifies_with_their_name_and_gets_a_working_token(): void
    {
        $this->postJson('/api/v1/auth/code', ['phone' => '076123456']);

        $response = $this->postJson('/api/v1/auth/verify', [
            'phone' => '076123456', 'code' => $this->codeFor(self::PHONE), 'name' => 'Aminata Kamara', 'district' => 'Bo', 'area' => 'Bo town', 'device_name' => 'Tecno Spark',
        ])->assertOk()->assertJsonPath('user.name', 'Aminata Kamara')->assertJsonPath('user.phone', self::PHONE);

        $user = User::where('phone', self::PHONE)->sole();
        $this->assertSame('Tecno Spark', $user->tokens()->sole()->name);

        $this->withToken($response->json('token'))->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.display_phone', '076 123 456');
    }

    public function test_a_new_user_must_give_a_name_and_where_they_live(): void
    {
        $this->postJson('/api/v1/auth/code', ['phone' => '076123456'])->assertJsonPath('needs_name', true)->assertJsonPath('needs_location', true);

        $this->postJson('/api/v1/auth/verify', ['phone' => '076123456', 'code' => $this->codeFor(self::PHONE)])
            ->assertUnprocessable()->assertJsonValidationErrors([
                'name' => 'Please tell us your full name.',
                'district' => 'Please choose the district where you live.',
                'area' => 'Please type your town or area, e.g. Lumley or Bo town.',
            ]);
        $this->postJson('/api/v1/auth/verify', ['phone' => '076123456', 'code' => $this->codeFor(self::PHONE), 'name' => 'Aminata', 'district' => 'Lagos', 'area' => 'Ikeja'])
            ->assertJsonValidationErrors('district');
    }

    public function test_an_older_account_without_a_location_gives_it_once_and_can_edit_it(): void
    {
        $user = User::factory()->create(['phone' => self::PHONE, 'district' => null, 'area' => null]);
        $this->postJson('/api/v1/auth/code', ['phone' => '076123456'])->assertJsonPath('needs_name', false)->assertJsonPath('needs_location', true);

        $this->postJson('/api/v1/auth/verify', ['phone' => '076123456', 'code' => $this->codeFor(self::PHONE), 'district' => 'Kenema', 'area' => 'Kenema town'])->assertOk()
            ->assertJsonPath('user.district', 'Kenema')->assertJsonPath('user.has_details', true);

        Sanctum::actingAs($user->fresh());
        $this->postJson('/api/v1/me', ['name' => 'Aminata Kamara', 'district' => 'Bo', 'area' => 'Bo town'])->assertOk()->assertJsonPath('data.area', 'Bo town')
            ->assertJsonPath('data.district', 'Bo');
    }

    public function test_a_wrong_code_is_refused_without_a_token(): void
    {
        User::factory()->create(['phone' => self::PHONE]);
        $this->postJson('/api/v1/auth/code', ['phone' => '076123456']);
        $wrong = $this->codeFor(self::PHONE) === '000000' ? '111111' : '000000';

        $this->postJson('/api/v1/auth/verify', ['phone' => '076123456', 'code' => $wrong])
            ->assertUnprocessable()->assertJsonValidationErrors('code')->assertJsonMissingPath('token');
    }

    public function test_the_hourly_code_limit_returns_429(): void
    {
        for ($i = 0; $i < 5; $i++) {
            app(OtpService::class)->send(self::PHONE);
        }

        $this->postJson('/api/v1/auth/code', ['phone' => '076123456'])
            ->assertStatus(429)->assertJson(['message' => 'Too many codes were sent to this number. Please try again in an hour.']);
    }

    public function test_requests_without_a_token_get_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->getJson('/api/v1/cart')->assertUnauthorized();
        $this->withToken('not-a-real-token')->getJson('/api/v1/orders')->assertUnauthorized();
    }

    public function test_logging_out_revokes_only_that_phones_token(): void
    {
        $user = User::factory()->create();
        $phone = $user->createToken('phone')->plainTextToken;
        $tablet = $user->createToken('tablet')->plainTextToken;

        $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame(['tablet'], $user->tokens()->pluck('name')->all());
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($tablet)->getJson('/api/v1/me')->assertOk();
    }
}
