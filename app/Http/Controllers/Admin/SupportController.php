<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConversationType;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\ChatService;

class SupportController extends Controller
{
    public function __construct(private ChatService $chat) {}

    /** Every customer's conversation with the team, newest activity first, with unread counts. */
    public function index()
    {
        $conversations = Conversation::where('type', ConversationType::Support)
            ->whereNotNull('last_message_at')
            ->with('owner', 'latestMessage')
            ->orderByDesc('last_message_at')
            ->paginate(25);

        return view('admin.support.index', [
            'conversations' => $conversations,
            'unread' => $this->chat->supportUnreadByConversation(),
        ]);
    }

    public function show(Conversation $conversation)
    {
        abort_unless($conversation->type === ConversationType::Support, 404);

        $conversation->load('owner');
        $panel = $this->chat->panel($conversation, auth()->user());
        $this->chat->markRead($conversation, auth()->user());

        return view('admin.support.show', ['conversation' => $conversation, 'panel' => $panel]);
    }
}
