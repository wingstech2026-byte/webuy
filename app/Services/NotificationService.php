<?php

namespace App\Services;

use App\Jobs\SendPushJob;
use App\Models\AppNotification;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

/**
 * Notifications in the app (the bell), and phone push notifications when Firebase is set up.
 * Every SMS WiBuy sends to a member (except login codes) also lands here, so the app shows order,
 * payment, deal and chat updates without extra wiring.
 */
class NotificationService
{
    public function notify(User $user, string $title, string $body, ?string $link = null): AppNotification
    {
        $notification = AppNotification::create([
            'user_id' => $user->id,
            'title' => Str::limit($title, 117),
            'body' => Str::limit($body, 497),
            'link' => $link,
        ]);

        if (FcmClient::configured() && $user->deviceTokens()->exists()) {
            try {
                SendPushJob::dispatch($notification->id);
            } catch (Throwable $e) {
                report($e); // a push problem must never break the action that caused it
            }
        }

        return $notification;
    }

    /** An SMS was sent to this phone: show it in the app too (brand text trimmed off). */
    public function fromSms(string $phone, string $message): void
    {
        $user = User::where('phone', $phone)->first();

        if (! $user) {
            return;
        }

        $body = Str::of($message)->after(config('wibuy.name').': ')->replaceLast(' '.config('wibuy.tagline'), '')->trim()->toString();

        $this->notify($user, $this->titleFor($body), $body, $this->linkFor($body));
    }

    private function titleFor(string $body): string
    {
        return match (true) {
            Str::contains($body, ['payment', 'paid', 'refund'], true) => 'Payment',
            Str::contains($body, ['order', 'Order'], true) => 'Your order',
            Str::contains($body, ['Lightning deal'], true) => 'Lightning deal',
            Str::contains($body, ['messages'], true) => 'New messages',
            Str::contains($body, ['WiBuy Pass'], true) => 'WiBuy Pass',
            Str::contains($body, ['Inspection'], true) => 'Inspection',
            Str::contains($body, ['WiBuy Global'], true) => 'WiBuy Global',
            default => config('wibuy.name'),
        };
    }

    /** Open the right screen when the notification is tapped (order numbers WB-XXXXXXXX). */
    private function linkFor(string $body): ?string
    {
        if (preg_match('/\b(WB-[A-Z0-9]{6,12})\b/', $body, $match)) {
            $id = Order::where('number', $match[1])->value('id');

            return $id ? "/orders/{$id}" : null;
        }

        return Str::contains($body, 'messages') ? '/chat' : null;
    }

    public function unreadCount(User $user): int
    {
        return AppNotification::where('user_id', $user->id)->whereNull('read_at')->count();
    }
}
