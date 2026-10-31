<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendMessageRequest;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReport;
use App\Models\User;
use App\Services\ChatService;
use App\Support\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

class ChatController extends Controller
{
    public function __construct(private ChatService $chat) {}

    /** My conversations: the WiBuy team plus (when direct chat is on) buyers and sellers I talk to. */
    public function index(Request $request)
    {
        $user = $request->user();
        $support = $this->chat->supportFor($user);
        Presence::touch($user->id);

        $rooms = ConversationParticipant::where('user_id', $user->id)
            ->where('conversation_id', '!=', $support->id)
            ->with(['conversation.latestMessage.user', 'conversation.participants.user.seller'])
            ->get()
            ->sortByDesc(fn ($p) => $p->conversation->last_message_at)
            ->values();

        return view('chat.index', [
            'support' => $support->load('latestMessage.user'),
            'rooms' => $rooms,
            'unread' => $this->chat->unreadByConversation($user),
        ]);
    }

    /** Full-page chat (support, direct). */
    public function show(Request $request, Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        $user = $request->user();
        Presence::touch($user->id);
        $panel = $this->panel($conversation, $user);
        $this->chat->markRead($conversation, $user);

        return view('chat.show', ['conversation' => $conversation, 'panel' => $panel]);
    }

    /** Messages as rendered HTML. No cursor: the latest page. before_id: older. after_id: newer. */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);
        $user = $request->user();
        Presence::touch($user->id);

        $before = $request->integer('before_id');
        $after = $request->integer('after_id');

        $messages = match (true) {
            $before > 0 => $this->chat->older($conversation, $before),
            $after > 0 => $this->chat->newer($conversation, $after),
            default => $this->chat->latest($conversation),
        };

        return response()->json([
            'html' => view('chat.messages', [
                'messages' => $messages, 'conversation' => $conversation, 'viewer' => $user,
            ])->render(),
            'count' => $messages->count(),
            'first_id' => $messages->first()?->id,
            'last_id' => $messages->last()?->id,
            // Only meaningful when paging backwards.
            'has_more' => $after > 0 ? null : ($messages->isNotEmpty() && $this->chat->hasOlder($conversation, $messages->first()->id)),
        ]);
    }

    public function store(SendMessageRequest $request, Conversation $conversation): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if ($reason = $this->chat->postingBlockedReason($user, $conversation)) {
            return $this->denied($request, $reason);
        }

        Presence::touch($user->id);

        try {
            $message = $this->chat->post(
                $user,
                $conversation,
                $request->input('body'),
                $request->file('image'),
                $request->file('voice'),
            );
        } catch (RuntimeException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage(), 'errors' => ['image' => [$e->getMessage()]]], 422)
                : back()->withErrors(['image' => $e->getMessage()])->withFragment('chat');
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'id' => $message->id], 201)
            : back()->withFragment('chat');
    }

    public function read(Request $request, Conversation $conversation): Response
    {
        $this->authorize('view', $conversation);
        $this->chat->markRead($conversation, $request->user());
        Presence::touch($request->user()->id);

        return response()->noContent();
    }

    /** Muted conversations stop unread badges and "new messages" SMS. */
    public function mute(Request $request, Conversation $conversation): RedirectResponse|JsonResponse
    {
        $this->authorize('view', $conversation);

        $participant = $this->chat->join($conversation, $request->user());
        $participant->update(['is_muted' => ! $participant->is_muted]);

        return $request->expectsJson()
            ? response()->json(['muted' => $participant->is_muted])
            : back()->with('status', $participant->is_muted ? 'Chat muted.' : 'Chat unmuted.');
    }

    public function report(Request $request, Message $message): RedirectResponse|JsonResponse
    {
        $this->authorize('view', $message->conversation);

        $request->validate(['reason' => ['nullable', 'string', 'max:200']]);

        abort_if(
            $message->isSystem() || $message->isDeleted() || $message->user_id === $request->user()->id,
            422,
            'This message cannot be reported.',
        );

        MessageReport::firstOrCreate(
            ['message_id' => $message->id, 'reporter_id' => $request->user()->id],
            ['reason' => Str::limit((string) $request->input('reason'), 200, '')],
        );

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('status', 'Thank you. The WiBuy team will look at this message.');
    }

    /** Polled by the header badge. */
    public function unread(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'count' => $this->chat->unreadFor($user) + ($user->is_admin ? $this->chat->supportUnreadTotal() : 0),
        ]);
    }

    /** Heartbeat from an open chat: people with chat open do not get "new messages" texts. */
    public function ping(Request $request): Response
    {
        Presence::touch($request->user()->id);

        return response()->noContent();
    }

    /** One-to-one chat. Off unless CHAT_DIRECT_ENABLED=true. */
    public function direct(Request $request, User $user): RedirectResponse
    {
        abort_unless(config('chat.direct_enabled'), 404);
        abort_if($user->id === $request->user()->id, 422, 'You cannot chat with yourself.');
        abort_if($request->user()->isChatBanned(), 403, 'You have been blocked from chat.');

        return redirect()->route('chat.show', $this->chat->directBetween($request->user(), $user));
    }

    /** Everything the chat panel partial needs. */
    public function panel(Conversation $conversation, User $viewer): array
    {
        return $this->chat->panel($conversation, $viewer);
    }

    private function denied(Request $request, string $reason): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['message' => $reason, 'errors' => ['body' => [$reason]]], 403)
            : back()->withErrors(['body' => $reason])->withFragment('chat');
    }
}
