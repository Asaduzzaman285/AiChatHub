<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API Gateway JWT middleware.
 * Validates the JWT, extracts user claims, and passes them
 * as headers to downstream microservices.
 */
class JwtAuthMiddleware extends \App\Http\Middleware\JwtGatewayMiddleware
{
    // Alias — delegates to JwtGatewayMiddleware
}
