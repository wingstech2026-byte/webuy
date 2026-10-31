<?php

namespace App\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: writes the SMS to the log instead of sending it. */
class LogSender implements SmsSender
{
    public function __construct(private string $senderId) {}

    public function send(string $to, string $message): void
    {
        Log::info("SMS [{$this->senderId}] to {$to}: {$message}");
    }
}
