<?php

return [
    'enabled' => (bool) env('GOOGLE_WALLET_ENABLED', false),

    'issuer_id' => env('GOOGLE_WALLET_ISSUER_ID'),

    'issuer_name' => env('GOOGLE_WALLET_ISSUER_NAME', env('APP_NAME')),

    'service_account_json' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_JSON'),

    'service_account_file' => env('GOOGLE_WALLET_SERVICE_ACCOUNT_FILE'),

    'id_prefix' => env('GOOGLE_WALLET_ID_PREFIX', 'hievents'),

    'origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('GOOGLE_WALLET_ORIGINS', ''))),
    )),

    'api_base_url' => 'https://walletobjects.googleapis.com/walletobjects/v1',

    'token_endpoint' => 'https://oauth2.googleapis.com/token',

    'save_link_base_url' => 'https://pay.google.com/gp/v/save/',

    'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',

    'request_timeout_seconds' => (int) env('GOOGLE_WALLET_REQUEST_TIMEOUT', 10),
];
