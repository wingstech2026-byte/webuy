<?php

namespace Tests\Feature;

use App\Exceptions\OtpThrottledException;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\OtpService;
use App\Sms\SmsSender;
use Tests\TestCase;

class OtpLoginTest extends TestCase
{
    private const PHONE = '23276123456';

    public function test_login_page_loads(): void
    {
        $this->get('/login')->assertOk()->assertSee('Buy and sell easy for Salone.');
    }

    public function test_requesting_a_code_sends_a_branded_sms_and_stores_only_a_hash(): void
    {
        $this->post('/login', ['phone' => '076 123 456'])
            ->assertRedirect(route('login.verify'))
            ->assertSessionHas('otp_phone', self::PHONE);

        $code = $this->sms->lastCodeTo(self::PHONE);
        $this->assertNotNull($code);
        $this->assertSame(6, strlen($code));

        $message = $this->sms->lastTo(self::PHONE);
        $this->assertStringContainsString('WiBuy', $message);
        $this->assertStringContainsString('Buy and sell easy for Salone.', $message);

        $otp = OtpCode::where('phone', self::PHONE)->firstOrFail();
        $this->assertNotSame($code, $otp->code_hash);
        $this->assertStringNotContainsString($code, $otp->code_hash);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->post('/login', ['phone' => '12345'])->assertSessionHasErrors('phone');

        $this->assertSame(0, OtpCode::count());
        $this->assertSame([], $this->sms->sent);
    }

    public function test_new_user_logs_in_with_code_and_name(): void
    {
        $this->post('/login', ['phone' => '076123456']);
        $code = $this->sms->lastCodeTo(self::PHONE);

        $this->post('/login/verify', ['code' => $code, 'name' => 'Aminata Kamara'])
            ->assertRedirect(route('home'));

        $user = User::where('phone', self::PHONE)->firstOrFail();
        $this->assertSame('Aminata Kamara', $user->name);
        $this->assertFalse($user->is_admin);
        $this->assertAuthenticatedAs($user);
    }

    public function test_new_user_must_give_a_name(): void
    {
        $this->post('/login', ['phone' => '076123456']);
        $code = $this->sms->lastCodeTo(self::PHONE);

        $this->post('/login/verify', ['code' => $code])->assertSessionHasErrors('name');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['phone' => self::PHONE]);
    }

    public function test_returning_user_does_not_need_a_name(): void
    {
        $user = User::factory()->create(['phone' => self::PHONE, 'name' => 'Existing Person']);

        $this->post('/login', ['phone' => '076123456']);
        $this->post('/login/verify', ['code' => $this->sms->lastCodeTo(self::PHONE)])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Existing Person', $user->fresh()->name);
    }

    public function test_admin_is_sent_to_the_admin_panel(): void
    {
        User::factory()->admin()->create(['phone' => self::PHONE]);

        $this->post('/login', ['phone' => '076123456']);
        $this->post('/login/verify', ['code' => $this->sms->lastCodeTo(self::PHONE)])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_wrong_code_is_rejected(): void
    {
        User::factory()->create(['phone' => self::PHONE]);
        $this->post('/login', ['phone' => '076123456']);
        $code = $this->sms->lastCodeTo(self::PHONE);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->post('/login/verify', ['code' => $wrong])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_code_is_locked_after_five_wrong_attempts(): void
    {
        User::factory()->create(['phone' => self::PHONE]);
        $this->post('/login', ['phone' => '076123456']);
        $code = $this->sms->lastCodeTo(self::PHONE);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login/verify', ['code' => $wrong])->assertSessionHasErrors('code');
        }

        // Even the right code no longer works.
        $this->post('/login/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_code_expires_after_ten_minutes(): void
    {
        User::factory()->create(['phone' => self::PHONE]);
        $this->post('/login', ['phone' => '076123456']);
        $code = $this->sms->lastCodeTo(self::PHONE);

        $this->travel(11)->minutes();

        $this->post('/login/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_code_works_just_before_it_expires(): void
    {
        User::factory()->create(['phone' => self::PHONE]);
        $this->post('/login', ['phone' => '076123456']);
        $code = $this->sms->lastCodeTo(self::PHONE);

        $this->travel(9)->minutes();

        $this->post('/login/verify', ['code' => $code])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_a_code_can_only_be_used_once(): void
    {
        User::factory()->create(['phone' => self::PHONE]);
        $this->post('/login', ['phone' => '076123456']);
        $code = $this->sms->lastCodeTo(self::PHONE);

        $this->post('/login/verify', ['code' => $code])->assertRedirect();
        $this->post('/logout');
        $this->assertGuest();

        // Replaying the same code needs a fresh send first: the session phone was cleared.
        $this->withSession(['otp_phone' => self::PHONE])
            ->post('/login/verify', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_new_code_replaces_the_old_one(): void
    {
        User::factory()->create(['phone' => self::PHONE]);
        $service = app(OtpService::class);

        $first = $service->send(self::PHONE);
        $second = $service->send(self::PHONE);

        if ($first !== $second) {
            $this->assertFalse($service->verify(self::PHONE, $first));
        }
        $this->assertTrue($service->verify(self::PHONE, $second));
    }

    public function test_a_phone_can_only_get_five_codes_per_hour(): void
    {
        $service = app(OtpService::class);

        for ($i = 0; $i < 5; $i++) {
            $service->send(self::PHONE);
        }

        $this->expectException(OtpThrottledException::class);
        $service->send(self::PHONE);
    }

    public function test_the_http_endpoint_reports_the_hourly_limit(): void
    {
        $service = app(OtpService::class);
        for ($i = 0; $i < 5; $i++) {
            $service->send(self::PHONE);
        }

        $this->post('/login', ['phone' => '076123456'])->assertSessionHasErrors('phone');
        $this->assertCount(5, OtpCode::all());
    }

    public function test_hourly_limit_resets_after_an_hour(): void
    {
        $service = app(OtpService::class);
        for ($i = 0; $i < 5; $i++) {
            $service->send(self::PHONE);
        }

        $this->travel(61)->minutes();

        $this->assertNotEmpty($service->send(self::PHONE));
    }

    public function test_the_send_endpoint_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['phone' => '12345']);
        }

        $this->post('/login', ['phone' => '12345'])->assertStatus(429);
    }

    public function test_verify_page_without_a_started_login_goes_back_to_login(): void
    {
        $this->get('/login/verify')->assertRedirect(route('login'));
    }

    public function test_logging_out_works(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_sms_failures_never_break_the_login_request(): void
    {
        $this->app->instance(SmsSender::class, new class implements SmsSender
        {
            public function send(string $to, string $message): void
            {
                throw new \RuntimeException('provider down');
            }
        });

        $this->post('/login', ['phone' => '076123456'])->assertRedirect(route('login.verify'));
    }
}
