<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\SmsService;
use App\Sms\SmsMessages;
use App\Support\Presence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Texts people who are not looking at the chat: "You have new messages on WiBuy".
 * At most one SMS per person per conversation per hour, never for system messages,
 * never to the sender, never to people who muted the conversation.
 */
class NotifyOfflineParticipantsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $messageId)
    {
        $this->afterCommit();
    }

    public function handle(SmsService $sms): void
    {
        $message = Message::with('conversation')->find($this->messageId);

        if (! $message || $message->isSystem() || $message->isDeleted()) {
            return;
        }

        $cooldown = now()->addMinutes(config('chat.sms_cooldown_minutes'));

        $message->conversation->participants()
            ->where('user_id', '!=', $message->user_id ?? 0)
            ->where('is_muted', false)
            ->with('user')
            ->cursor()
            ->each(function ($participant) use ($message, $sms, $cooldown) {
                $user = $participant->user;

                if (! $user || Presence::isOnline($user->id)) {
                    return;
                }

                // Cache::add is atomic: only the first message in the hour wins.
                if (! Cache::add("chat:sms:{$message->conversation_id}:{$user->id}", 1, $cooldown)) {
                    return;
                }

                $sms->send($user->phone, SmsMessages::newChatMessages());
            });
    }
}
