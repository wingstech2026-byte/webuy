<?php

namespace App\Http\Controllers\Admin;

use App\Events\MessageDeleted;
use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class ChatModerationController extends Controller
{
    /** Messages people reported, most reported first. */
    public function reports()
    {
        $messages = Message::whereHas('reports', fn ($q) => $q->whereNull('resolved_at'))
            ->with(['user', 'conversation', 'reports' => fn ($q) => $q->whereNull('resolved_at')->with('reporter')])
            ->withCount(['reports as open_reports' => fn ($q) => $q->whereNull('resolved_at')])
            ->orderByDesc('open_reports')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.chat.reports', ['messages' => $messages]);
    }

    /** Remove a message for everyone. The text is kept in the database for the record but never shown. */
    public function destroy(Request $request, Message $message): RedirectResponse|JsonResponse
    {
        if (! $message->isDeleted()) {
            $message->forceFill(['deleted_at' => now()])->save();
            $message->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);

            try {
                event(new MessageDeleted($message));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back()->with('status', 'Message removed.');
    }

    /** Close the reports on a message without removing it. */
    public function dismiss(Message $message): RedirectResponse
    {
        $message->reports()->whereNull('resolved_at')->update(['resolved_at' => now()]);

        return back()->with('status', 'Reports dismissed.');
    }

    /** Block or unblock someone from chatting with sellers (direct chat). */
    public function toggleBan(User $user): RedirectResponse
    {
        abort_if($user->is_admin, 422, 'Admins cannot be blocked.');

        $user->forceFill(['chat_banned_at' => $user->isChatBanned() ? null : now()])->save();

        return back()->with('status', $user->isChatBanned()
            ? "{$user->name} is blocked from chat."
            : "{$user->name} can chat again.");
    }
}
