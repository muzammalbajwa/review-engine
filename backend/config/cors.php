<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Locked to the single known frontend origin (.claude/SECURITY.md #6).
    | No wildcards. FRONTEND_URL must be set to the exact scheme+host+port
    | of the Next.js app in every environment.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(explode(',', (string) env('FRONTEND_URL', ''))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With', 'X-XSRF-TOKEN'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Required for Sanctum's cookie-based SPA auth (Phase 1). Inert until
    // that auth flow exists; safe to enable now since allowed_origins can
    // never be a wildcard while this is true (browsers reject that combo).
    'supports_credentials' => true,

];
