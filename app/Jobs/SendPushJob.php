<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Services\FcmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Send one notification to every phone of its person. Dead tokens are removed. */
class SendPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public int $notificationId) {}

    public function handle(FcmClient $fcm): void
    {
        $notification = AppNotification::find($this->notificationId);

        if (! $notification || ! FcmClient::configured()) {
            return;
        }

        foreach (DeviceToken::where('user_id', $notification->user_id)->get() as $device) {
            $alive = $fcm->send($device->token, $notification->title, $notification->body, array_filter([
                'notification_id' => $notification->id,
                'link' => $notification->link,
            ]));

            $alive ? $device->forceFill(['last_used_at' => now()])->save() : $device->delete();
        }
    }
}
