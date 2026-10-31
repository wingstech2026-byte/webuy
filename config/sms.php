<?php

return [

    // log | http
    'driver' => env('SMS_DRIVER', 'log'),

    'sender_id' => env('SMS_SENDER_ID', 'WIBUY'),

    /*
    | Generic bulk-SMS HTTP API. WiBuy POSTs JSON:
    |   {"from": "WIBUY", "to": "23276123456", "message": "..."}
    | with "Authorization: Bearer <token>". Adjust Sms\HttpSender if your
    | provider expects a different payload.
    */
    'http' => [
        'url' => env('SMS_HTTP_URL'),
        'token' => env('SMS_HTTP_TOKEN'),
        'timeout' => 10,
    ],

];
