<?php

// Minimal config so JwtGatewayMiddleware's config('jwt.secret') resolves —
// api-gateway decodes tokens directly with firebase/php-jwt rather than
// tymon/jwt-auth (that's only installed in auth-service, the token issuer).
return [
    'secret' => env('JWT_SECRET'),
];
