<?php

return [
    'enabled' => (bool) env('APPLE_WALLET_ENABLED', false),

    'pass_type_identifier' => env('APPLE_WALLET_PASS_TYPE_IDENTIFIER'),

    'team_identifier' => env('APPLE_WALLET_TEAM_IDENTIFIER'),

    'organization_name' => env('APPLE_WALLET_ORGANIZATION_NAME') ?: env('APP_NAME'),

    'certificate' => env('APPLE_WALLET_CERTIFICATE'),

    'certificate_file' => env('APPLE_WALLET_CERTIFICATE_FILE'),

    'certificate_password' => env('APPLE_WALLET_CERTIFICATE_PASSWORD', ''),

    'wwdr_certificate' => env('APPLE_WALLET_WWDR_CERTIFICATE'),

    'wwdr_certificate_file' => env('APPLE_WALLET_WWDR_CERTIFICATE_FILE'),

    'serial_prefix' => env('APPLE_WALLET_SERIAL_PREFIX', 'hievents'),

    'api_url' => env('APPLE_WALLET_API_URL') ?: rtrim((string) env('APP_FRONTEND_URL', 'http://localhost'), '/').'/api',

    'apns_base_url' => env('APPLE_WALLET_APNS_BASE_URL') ?: 'https://api.push.apple.com',

    'request_timeout_seconds' => (int) env('APPLE_WALLET_REQUEST_TIMEOUT', 10),

    'image_max_bytes' => 5 * 1024 * 1024,

    'image_cache_seconds' => 3600,
];
