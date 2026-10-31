<?php

namespace App\Services;

use App\Jobs\SendSmsJob;
use Throwable;

class SmsService
{
    /**
     * Queue an SMS. A messaging problem must never break an order or a payment,
     * so any dispatch error is reported and swallowed.
     */
    public function send(?string $phone, string $message, bool $inApp = true): void
    {
        if (! $phone) {
            return;
        }

        try {
            SendSmsJob::dispatch($phone, $message);
        } catch (Throwable $e) {
            report($e);
        }

        // Members also see it in the app's notifications (and as a push notification when set up).
        if ($inApp) {
            try {
                app(NotificationService::class)->fromSms($phone, $message);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
