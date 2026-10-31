<?php

namespace Tests;

use App\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\RecordingSmsSender;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected RecordingSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        // Tests do not need the compiled CSS/JS.
        $this->withoutVite();

        $this->sms = new RecordingSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }
}
