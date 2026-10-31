<?php

return [

    // fake | pawapay
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    'currency' => env('PAYMENT_CURRENCY', 'SLE'),

    'pawapay' => [
        'base_url' => env('PAWAPAY_BASE_URL', 'https://api.sandbox.pawapay.io'),
        'token' => env('PAWAPAY_API_TOKEN'),
        'timeout' => 15,
        'statement_description' => 'WiBuy order',
    ],

    // Our operator => pawaPay operator code.
    'operators' => [
        'ORANGE' => env('PAWAPAY_OPERATOR_ORANGE', 'ORANGE_SLE'),
        'AFRIMONEY' => env('PAWAPAY_OPERATOR_AFRIMONEY', 'AFRIMONEY_SLE'),
    ],

];
