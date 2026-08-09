<?php

declare(strict_types=1);

return [
    'mtn_momo' => [
        'enabled' => (bool) env('MTN_MOMO_ENABLED', false),
        'base_url' => rtrim((string) env('MTN_MOMO_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'), '/'),
        'subscription_key' => env('MTN_MOMO_SUBSCRIPTION_KEY'),
        'api_user' => env('MTN_MOMO_API_USER'),
        'api_key' => env('MTN_MOMO_API_KEY'),
        'target_environment' => env('MTN_MOMO_TARGET_ENVIRONMENT', 'sandbox'),
        'callback_url' => env('MTN_MOMO_CALLBACK_URL', rtrim((string) env('APP_URL', ''), '/').'/api/integrations/mtn-momo/callback'),
        'currency' => env('MTN_MOMO_CURRENCY', 'UGX'),
        'timeout_seconds' => (int) env('MTN_MOMO_TIMEOUT_SECONDS', 15),
    ],

    // Airtel Money is deliberately configuration-only until ICGU merchant onboarding
    // provides the current Uganda production API contract and credentials.
    'airtel_money' => [
        'enabled' => (bool) env('AIRTEL_MONEY_ENABLED', false),
        'base_url' => env('AIRTEL_MONEY_BASE_URL'),
        'client_id' => env('AIRTEL_MONEY_CLIENT_ID'),
        'client_secret' => env('AIRTEL_MONEY_CLIENT_SECRET'),
        'country' => env('AIRTEL_MONEY_COUNTRY', 'UG'),
        'currency' => env('AIRTEL_MONEY_CURRENCY', 'UGX'),
    ],

    'supabase' => [
        'url' => env('SUPABASE_URL'),
        // New Supabase sb_secret_* keys are server-only and replace legacy service_role keys.
        'secret_key' => env('SUPABASE_SECRET_KEY'),
    ],

    'sms' => [
        'enabled' => (bool) env('SMS_ENABLED', false),
        'endpoint' => env('SMS_HTTP_ENDPOINT'),
        'token' => env('SMS_HTTP_TOKEN'),
        'sender' => env('SMS_SENDER', 'ICGU'),
    ],
];
