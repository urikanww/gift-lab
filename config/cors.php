<?php

declare(strict_types=1);

/*
 * CORS for the decoupled SPA + Sanctum cookie auth. Credentials are enabled and
 * origins are read from env (never "*", which is invalid with credentials).
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    // Enumerated rather than '*': with supports_credentials the wildcard is
    // broader than needed. These cover the SPA's verbs + the CORS preflight.
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
