<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Auth\Access\Response;

class ConversationPolicy
{
    public function __construct(private ChatService $chat) {}

    /** Support: the customer and admins. Direct (buyer and seller): the two people. */
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->chat->canView($user, $conversation);
    }

    /** Denies with a human-readable reason that the chat shows to the person. */
    public function post(User $user, Conversation $conversation): Response
    {
        $reason = $this->chat->postingBlockedReason($user, $conversation);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }
}
