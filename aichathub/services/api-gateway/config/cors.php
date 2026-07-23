<?php

return [

    // No config/cors.php existed before — Laravel's HandleCors middleware is in the
    // default stack regardless, so it was silently falling back to the framework's own
    // wide-open default (allowed_origins: ['*']). This is the only service that needs
    // real CORS: the browser only ever talks to api-gateway directly (per the frontend's
    // API base URL) — backend-to-backend calls are server-to-server and never subject
    // to CORS (a browser-only enforcement mechanism), so locking down the other 8
    // services would be pure busywork with zero security benefit.

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Read directly from env(), not config('services.frontend_url') — Laravel loads
    // config files alphabetically, so 'cors.php' is loaded before 'services.php' exists
    // in the container; cross-referencing it here silently resolved to null (confirmed
    // live: Access-Control-Allow-Origin came back present but empty for every origin).
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Stays false — auth is Bearer-token-in-header (localStorage), not cookie-based.
    // The new has_session marker cookie (frontend middleware) is same-origin only,
    // set by the frontend itself and never sent cross-origin to this gateway.
    'supports_credentials' => false,

];
