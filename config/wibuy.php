<?php

return [

    'name' => env('APP_NAME', 'WiBuy'),

    'tagline' => env('WIBUY_TAGLINE', 'Buy and sell easy for Salone.'),

    // All money is SLE (New Leone) with 2 decimal places.
    'currency' => 'SLE',
    'currency_symbol' => 'Le',

    'support_whatsapp' => env('SUPPORT_WHATSAPP'),

    /*
    |--------------------------------------------------------------------------
    | Money rules (the admin can change commission and minimum payout in the panel;
    | these are the starting values)
    |--------------------------------------------------------------------------
    */
    // WiBuy's share of an order's item subtotal, in percent.
    'commission_percent' => (float) env('COMMISSION_PERCENT', 5),
    // Smallest amount a seller can ask to be paid out.
    'min_payout' => env('MIN_PAYOUT', '100.00'),

    /*
    |--------------------------------------------------------------------------
    | Order timing
    |--------------------------------------------------------------------------
    */
    // Minutes an unpaid checkout holds its stock.
    'payment_hold_minutes' => (int) env('PAYMENT_HOLD_MINUTES', 15),
    // Hours a seller has to accept a paid order before the buyer is refunded automatically.
    'accept_within_hours' => (int) env('ACCEPT_WITHIN_HOURS', 24),
    // Days after "shipped"/"delivered" before an order completes by itself (if no dispute).
    'auto_complete_days' => (int) env('AUTO_COMPLETE_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    */
    'max_product_images' => 6,
    'per_page' => 24,
    'max_cart_quantity' => 100000,

    'images' => [
        'max_side' => 1080,
        'thumb_side' => 360,
        'quality' => 72,
        'thumb_quality' => 68,
        'max_upload_kb' => 6144,
        'max_pixels' => 25_000_000,
    ],

    // Districts of Sierra Leone: used for seller location, delivery areas and addresses.
    'districts' => [
        'Western Area Urban', 'Western Area Rural', 'Bo', 'Bombali', 'Bonthe', 'Falaba',
        'Kailahun', 'Kambia', 'Karene', 'Kenema', 'Koinadugu', 'Kono', 'Moyamba',
        'Port Loko', 'Pujehun', 'Tonkolili',
    ],

    'otp' => [
        'length' => (int) env('OTP_LENGTH', 6),
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'max_sends_per_hour' => (int) env('OTP_MAX_SENDS_PER_HOUR', 5),
    ],

    'admin' => [
        'phone' => env('ADMIN_PHONE', '076000000'),
        'name' => env('ADMIN_NAME', 'WiBuy Admin'),
    ],

    // Optional features (Part B). Off unless switched on in .env.
    'features' => [
        // AI shopping assistant. The Claude API is called from the server only; the key never reaches the app.
        'assistant' => (bool) env('ASSISTANT_ENABLED', false),
        // WiBuy Global: import orders from China (sea or air), deposit now and balance on arrival.
        'global' => (bool) env('WIBUY_GLOBAL_ENABLED', false),
    ],

    'assistant' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ASSISTANT_MODEL', 'claude-opus-5-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    ],

];
