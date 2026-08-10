<?php

declare(strict_types=1);

return [
    'driver' => env('SESSION_DRIVER', 'database'),
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'expire_on_close' => (bool) env('SESSION_EXPIRE_ON_CLOSE', false),
    'encrypt' => (bool) env('SESSION_ENCRYPT', true),
    'files' => storage_path('framework/sessions'),
    'connection' => env('SESSION_CONNECTION'),
    'table' => env('SESSION_TABLE', 'sessions'),
    'store' => env('SESSION_STORE'),
    'lottery' => [2, 100],
    // Use a dedicated cookie name for this application so stale cookies from
    // earlier pilot deployments cannot be mistaken for the current session.
    'cookie' => env('SESSION_COOKIE', 'icgu_portal_session'),
    'path' => '/',
    // Keep the session cookie host-only. This works consistently on both the
    // Laravel Cloud hostname and any attached custom domain and avoids a stale
    // SESSION_DOMAIN causing the browser to reject the cookie.
    'domain' => null,
    'secure' => true,
    'http_only' => true,
    'same_site' => 'lax',
    'partitioned' => false,
];
