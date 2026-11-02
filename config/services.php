<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Phone push notifications (Firebase Cloud Messaging, HTTP v1). Off until both are set.
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS'), // path to the Firebase service account JSON file
    ],

    // Maps. Tiles are only shown in the browser (attribution required). Routing is optional:
    // with no ROUTING_URL, distances are straight-line x 1.3 and labelled as an estimate.
    // Do not point these at the free public OpenStreetMap/Nominatim/OSRM servers in production.
    'maps' => [
        'tiles' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    ],
    'routing' => [
        'url' => env('ROUTING_URL'), // e.g. a self-hosted OSRM: http://osrm.internal:5000
    ],

    // Ordering by SMS and USSD for people without data: the gateway posts incoming texts to
    // /webhooks/sms?token=... and USSD sessions to /webhooks/ussd?token=... Off until switched on.
    'sms_ordering' => [
        'enabled' => (bool) env('SMS_ORDERING_ENABLED', false),
        'token' => env('SMS_ORDERING_TOKEN'),          // a long random secret, also typed into the gateway's callback URL
        'number' => env('SMS_ORDERING_NUMBER'),        // the number buyers text, shown on product pages
        'ussd_code' => env('SMS_ORDERING_USSD_CODE'),  // e.g. *123#
    ],

    // WhatsApp Business Cloud API (Meta). Shoppers text or send a photo; WiBuy replies with matching products.
    // Webhook: https://<your site>/webhooks/whatsapp, with WHATSAPP_VERIFY_TOKEN as the verify token.
    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_ENABLED', false),
        'token' => env('WHATSAPP_TOKEN'),                     // permanent access token
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),       // any secret you choose; typed into Meta's webhook settings
        'app_secret' => env('WHATSAPP_APP_SECRET'),           // signs every webhook call (X-Hub-Signature-256)
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com/v21.0'),
    ],

];
