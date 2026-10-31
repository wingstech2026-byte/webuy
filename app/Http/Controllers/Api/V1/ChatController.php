<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendMessageRequest;
use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Services\ChatService;
use App\Support\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/** Chat in the app: the WiBuy support team, and (behind CHAT_DIRECT_ENABLED) buyer-seller chats. Polled. */
class ChatController extends Controller
{
    public function __construct(private ChatService $chat) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $support = $this->chat->supportFor($user);
        $unread = $this->chat->unreadByConversation($user);

        $rooms = ConversationParticipant::where('user_id', $user->id)->where('conversation_id', '!=', $support->id)
            ->with(['conversation.latestMessage', 'conversation.participants.user.seller'])->get()
            ->sortByDesc(fn ($p) => $p->conversation->last_message_at)->values()
            ->map(fn ($p) => $this->room($p->conversation, $user, $unread));

        return response()->json([
            'support' => array_merge($this->room($support->load('latestMessage'), $user, $unread), ['title' => 'WiBuy support']),
            'rooms' => $rooms,
            'direct_enabled' => (bool) config('chat.direct_enabled'),
            'poll_seconds' => (int) config('chat.poll_seconds'),
        ]);
    }

    private function room(Conversation $conversation, User $viewer, array $unread): array
    {
        $other = $conversation->participants->first(fn ($p) => $p->user_id !== $viewer->id)?->user;

        return [
            'id' => $conversation->id,
            'title' => $other?->seller?->shop_name ?? $other?->first_name ?? 'Chat',
            'last_message' => $conversation->latestMessage?->body,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'unread' => (int) ($unread[$conversation->id] ?? 0),
        ];
    }

    /** Start (or reopen) a chat with a seller or buyer. Off unless CHAT_DIRECT_ENABLED. */
    public function direct(Request $request, User $user): JsonResponse
    {
        abort_unless(config('chat.direct_enabled'), 404);
        abort_if($user->id === $request->user()->id, 422, 'You cannot chat with yourself.');
        abort_if($request->user()->isChatBanned(), 403, 'You have been blocked from chat.');

        return response()->json(['id' => $this->chat->directBetween($request->user(), $user)->id]);
    }

    /** The latest page; ?after_id= for newer (polling) or ?before_id= for older. */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        abort_if(Gate::denies('view', $conversation), 404);
        Presence::touch($request->user()->id);

        $before = $request->integer('before_id');
        $after = $request->integer('after_id');

        $messages = match (true) {
            $before > 0 => $this->chat->older($conversation, $before),
            $after > 0 => $this->chat->newer($conversation, $after),
            default => $this->chat->latest($conversation),
        };

        if ($messages->isNotEmpty()) {
            $this->chat->markRead($conversation, $request->user());
        }

        return response()->json([
            'data' => MessageResource::collection($messages->load('user'))->resolve($request),
            'can_post' => $this->chat->postingBlockedReason($request->user(), $conversation) === null,
            'has_more' => $after > 0 ? null : ($messages->isNotEmpty() && $this->chat->hasOlder($conversation, $messages->first()->id)),
        ]);
    }

    /** Send text and/or a photo (multipart "image"). */
    public function send(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        if ($reason = $this->chat->postingBlockedReason($request->user(), $conversation)) {
            return response()->json(['message' => $reason], 403);
        }

        try {
            $message = $this->chat->post($request->user(), $conversation, $request->input('body'), $request->file('image'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['image' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => (new MessageResource($message->load('user')))->resolve($request)], 201);
    }

    public function read(Request $request, Conversation $conversation): Response
    {
        abort_if(Gate::denies('view', $conversation), 404);
        $this->chat->markRead($conversation, $request->user());

        return response()->noContent();
    }
}
