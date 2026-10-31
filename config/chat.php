<?php

return [

    // One-to-one direct chat and voice notes (voice notes only exist inside direct chats).
    // Off by default: turn on later with CHAT_DIRECT_ENABLED=true.
    'direct_enabled' => (bool) env('CHAT_DIRECT_ENABLED', false),

    // Messages loaded first, and per "load older" request.
    'page_size' => (int) env('CHAT_PAGE_SIZE', 30),

    // Sending limit per user.
    'max_per_minute' => (int) env('CHAT_MAX_PER_MINUTE', 20),

    'max_length' => (int) env('CHAT_MAX_LENGTH', 1000),

    // At most one "new messages" SMS per person per conversation in this many minutes.
    'sms_cooldown_minutes' => (int) env('CHAT_SMS_COOLDOWN_MINUTES', 60),

    // A person counts as "online" (no SMS) when their chat page pinged within this many seconds.
    'online_seconds' => 90,

    // Browser polls this often when the WebSocket is not connected.
    'poll_seconds' => (int) env('CHAT_POLL_SECONDS', 5),

    'images' => [
        'disk' => 'public',
        'max_upload_kb' => 8192,
        'max_pixels' => 25_000_000, // refuse decompression bombs
        'max_side' => 1280,
        'quality' => 70,
        'thumb_side' => 320,
        'thumb_quality' => 70,
    ],

    'voice' => [
        'max_upload_kb' => 2048,
        // Laravel checks the extension guessed from the file's real type: Chrome records audio/webm
        // ("weba"), Safari audio/mp4 ("m4a"), and MP3 files are detected as "mpga".
        'mimes' => ['webm', 'weba', 'ogg', 'oga', 'opus', 'mp3', 'mpga', 'm4a', 'mp4', 'aac', 'wav'],
    ],

];
