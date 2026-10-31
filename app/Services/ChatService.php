<?php

namespace App\Services;

use App\Enums\ConversationType;
use App\Enums\MessageType;
use App\Events\MessageSent;
use App\Jobs\NotifyOfflineParticipantsJob;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Support\ChatImageProcessor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Chat: every customer has one support conversation with the WiBuy team, and (behind
 * CHAT_DIRECT_ENABLED) buyers and sellers can talk one to one.
 */
class ChatService
{
    public function __construct(private ChatImageProcessor $images) {}

    // ---------------------------------------------------------------- rooms

    /** Each customer has exactly one conversation with the WiBuy team. */
    public function supportFor(User $user): Conversation
    {
        $conversation = $this->firstOrCreate(['type' => ConversationType::Support, 'owner_id' => $user->id]);
        $this->join($conversation, $user);

        return $conversation;
    }

    public function directBetween(User $a, User $b): Conversation
    {
        $key = min($a->id, $b->id).'-'.max($a->id, $b->id);
        $conversation = $this->firstOrCreate(['type' => ConversationType::Direct, 'direct_key' => $key]);
        $this->join($conversation, $a);
        $this->join($conversation, $b);

        return $conversation;
    }

    /** Add someone to the member list (for unread badges and offline SMS). */
    public function join(Conversation $conversation, User $user): ConversationParticipant
    {
        $attributes = ['conversation_id' => $conversation->id, 'user_id' => $user->id];

        try {
            return ConversationParticipant::firstOrCreate($attributes, [
                // New members do not see the whole history as "unread".
                'last_read_message_id' => $conversation->messages()->max('id'),
                'last_read_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return ConversationParticipant::where($attributes)->firstOrFail();
        }
    }

    private function firstOrCreate(array $keys): Conversation
    {
        try {
            return Conversation::firstOrCreate($keys);
        } catch (UniqueConstraintViolationException) {
            return Conversation::where($keys)->firstOrFail();
        }
    }

    // ---------------------------------------------------------- permissions

    public function canView(User $user, Conversation $conversation): bool
    {
        return match ($conversation->type) {
            ConversationType::Support => $user->is_admin || $conversation->owner_id === $user->id,
            ConversationType::Direct => config('chat.direct_enabled')
                && $conversation->participants()->where('user_id', $user->id)->exists(),
        };
    }

    /** Why this person cannot post here, or null when they can. The text is shown to them. */
    public function postingBlockedReason(User $user, Conversation $conversation): ?string
    {
        if (! $this->canView($user, $conversation)) {
            return 'You cannot use this chat.';
        }

        // Blocked people can still write to the WiBuy team (to appeal), but not to other customers.
        if ($user->isChatBanned() && $conversation->type !== ConversationType::Support) {
            return 'You have been blocked from chat.';
        }

        return null;
    }

    // ------------------------------------------------------------- messages

    public function post(
        User $user,
        Conversation $conversation,
        ?string $body = null,
        ?UploadedFile $image = null,
        ?UploadedFile $voice = null,
    ): Message {
        $attributes = [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'type' => MessageType::Text,
            'body' => $body !== null && trim($body) !== '' ? trim($body) : null,
        ];

        if ($image) {
            $stored = $this->images->store($image);
            $attributes += ['image_path' => $stored['path'], 'thumb_path' => $stored['thumb']];
            $attributes['type'] = MessageType::Image;
        } elseif ($voice) {
            $attributes['audio_path'] = $voice->store('chat/audio', config('chat.images.disk'));
            $attributes['type'] = MessageType::Voice;
        }

        $message = DB::transaction(function () use ($attributes, $conversation, $user) {
            $message = Message::create($attributes);
            $conversation->forceFill(['last_message_at' => now()])->save();

            if ($user->is_admin) {
                // The team reading and replying in a support chat counts as "read" for the team.
                if ($conversation->type === ConversationType::Support) {
                    $conversation->forceFill(['admin_last_read_message_id' => $message->id])->save();
                }
            } else {
                $this->join($conversation, $user)->update([
                    'last_read_message_id' => $message->id,
                    'last_read_at' => now(),
                ]);
            }

            return $message;
        });

        $this->announce($message);

        // Buyer safety: someone asking to be paid outside WiBuy gets a warning in the chat.
        if ($conversation->type === ConversationType::Direct) {
            app(RiskService::class)->checkMessage($message, $conversation);
        }

        try {
            NotifyOfflineParticipantsJob::dispatch($message->id);
        } catch (Throwable $e) {
            report($e);
        }

        return $message;
    }

    private function announce(Message $message): void
    {
        try {
            event(new MessageSent($message));
        } catch (Throwable $e) {
            // Real-time is a bonus: browsers fall back to polling if broadcasting is down.
            report($e);
        }
    }

    // -------------------------------------------------------------- reading

    /** The newest messages, oldest first. */
    public function latest(Conversation $conversation, ?int $limit = null): Collection
    {
        return $conversation->messages()->with('user')
            ->orderByDesc('id')->limit($limit ?? config('chat.page_size'))
            ->get()->reverse()->values();
    }

    /** Up to $limit messages older than $beforeId, oldest first. */
    public function older(Conversation $conversation, int $beforeId, ?int $limit = null): Collection
    {
        return $conversation->messages()->with('user')->where('id', '<', $beforeId)
            ->orderByDesc('id')->limit($limit ?? config('chat.page_size'))
            ->get()->reverse()->values();
    }

    /** Messages newer than $afterId, oldest first. */
    public function newer(Conversation $conversation, int $afterId, int $limit = 100): Collection
    {
        return $conversation->messages()->with('user')->where('id', '>', $afterId)
            ->orderBy('id')->limit($limit)->get();
    }

    public function hasOlder(Conversation $conversation, int $firstId): bool
    {
        return $conversation->messages()->where('id', '<', $firstId)->exists();
    }

    /**
     * Everything the chat panel needs to render: the latest messages and whether this viewer may post.
     *
     * @return array<string, mixed>
     */
    public function panel(Conversation $conversation, ?User $viewer): array
    {
        $messages = $this->latest($conversation);

        return [
            'conversation' => $conversation,
            'messages' => $messages,
            'viewer' => $viewer,
            'hasMore' => $messages->isNotEmpty() && $this->hasOlder($conversation, $messages->first()->id),
            'blockedReason' => $viewer ? $this->postingBlockedReason($viewer, $conversation) : 'Log in to chat.',
            'canVoice' => config('chat.direct_enabled') && $conversation->type === ConversationType::Direct,
            'muted' => $viewer
                ? (bool) $conversation->participants()->where('user_id', $viewer->id)->value('is_muted')
                : false,
        ];
    }

    // --------------------------------------------------------------- unread

    public function markRead(Conversation $conversation, User $user): void
    {
        $latest = (int) $conversation->messages()->max('id');

        if ($user->is_admin && $conversation->type === ConversationType::Support) {
            $conversation->forceFill(['admin_last_read_message_id' => $latest])->save();

            return;
        }

        ConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $user->id)
            ->update(['last_read_message_id' => $latest, 'last_read_at' => now()]);
    }

    /** Unread messages from other people across the user's conversations. Muted chats and removed messages do not count. */
    public function unreadFor(User $user): int
    {
        return (int) $this->unreadQuery($user)->count();
    }

    /** @return array<int, int> conversation id => unread count */
    public function unreadByConversation(User $user): array
    {
        return $this->unreadQuery($user)
            ->select('m.conversation_id', DB::raw('count(*) as unread'))
            ->groupBy('m.conversation_id')
            ->pluck('unread', 'conversation_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    private function unreadQuery(User $user)
    {
        return DB::table('messages as m')
            ->join('conversation_participants as p', function ($join) use ($user) {
                $join->on('p.conversation_id', '=', 'm.conversation_id')->where('p.user_id', '=', $user->id);
            })
            ->where('p.is_muted', false)
            ->where('m.type', '!=', MessageType::System->value)
            ->whereNull('m.deleted_at')
            ->where('m.user_id', '!=', $user->id)
            ->whereRaw('m.id > coalesce(p.last_read_message_id, 0)');
    }

    /** Messages from customers the team has not read yet, across all support conversations. */
    public function supportUnreadTotal(): int
    {
        return (int) $this->supportUnreadQuery()->count();
    }

    /** @return array<int, int> support conversation id => unread count */
    public function supportUnreadByConversation(): array
    {
        return $this->supportUnreadQuery()
            ->select('m.conversation_id', DB::raw('count(*) as unread'))
            ->groupBy('m.conversation_id')
            ->pluck('unread', 'conversation_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    private function supportUnreadQuery()
    {
        return DB::table('messages as m')
            ->join('conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.type', ConversationType::Support->value)
            ->whereColumn('m.user_id', 'c.owner_id')
            ->whereNull('m.deleted_at')
            ->whereRaw('m.id > coalesce(c.admin_last_read_message_id, 0)');
    }
}
