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

];
