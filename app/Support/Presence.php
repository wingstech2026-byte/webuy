<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * "Is this person looking at their chat right now?" The chat page pings every 45 seconds
 * and on every fetch, so someone with the chat open counts as online. Offline people get an SMS.
 */
final class Presence
{
    public static function touch(int $userId): void
    {
        // Only write to the cache every 20 seconds per person, however often the page polls.
        if (Cache::add("chat:touch-lock:{$userId}", 1, 20)) {
            Cache::put("chat:online:{$userId}", now()->timestamp, config('chat.online_seconds'));
        }
    }

    public static function isOnline(int $userId): bool
    {
        return Cache::has("chat:online:{$userId}");
    }

    public static function forget(int $userId): void
    {
        Cache::forget("chat:online:{$userId}");
        Cache::forget("chat:touch-lock:{$userId}");
    }
}
