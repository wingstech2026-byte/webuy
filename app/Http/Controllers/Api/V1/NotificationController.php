<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\DeviceToken;
use App\Services\ChatService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** The notifications list, the unread badge, and registering phones for push notifications. */
class NotificationController extends Controller
{
    public function index(Request $request, NotificationService $notifications): JsonResponse
    {
        $page = AppNotification::where('user_id', $request->user()->id)->latest('id')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (AppNotification $n) => [
                'id' => $n->id, 'title' => $n->title, 'body' => $n->body, 'link' => $n->link,
                'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'unread' => $notifications->unreadCount($request->user()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Badge numbers for the app: unread notifications and chat messages. */
    public function counts(Request $request, NotificationService $notifications): JsonResponse
    {
        return response()->json([
            'notifications' => $notifications->unreadCount($request->user()),
            'chat' => app(ChatService::class)->unreadFor($request->user()),
        ]);
    }

    public function readAll(Request $request): Response
    {
        AppNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->noContent();
    }

    public function registerDevice(Request $request): Response
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
        ]);

        // A phone belongs to whoever logged in last on it.
        DeviceToken::updateOrCreate(['token' => $data['token']], ['user_id' => $request->user()->id, 'platform' => $data['platform'] ?? null, 'last_used_at' => now()]);

        return response()->noContent();
    }

    public function forgetDevice(Request $request): Response
    {
        $request->validate(['token' => ['required', 'string', 'max:255']]);
        DeviceToken::where('token', $request->input('token'))->where('user_id', $request->user()->id)->delete();

        return response()->noContent();
    }
}
