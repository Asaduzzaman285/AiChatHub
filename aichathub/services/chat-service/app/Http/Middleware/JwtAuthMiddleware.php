<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This service has no local user store — the API Gateway validates the JWT
 * (see api-gateway/app/Http/Middleware/JwtGatewayMiddleware.php) and forwards
 * the decoded identity as X-User-Id / X-User-Email / X-User-Status headers.
 * Requests that bypass the gateway (e.g. direct internal test scripts) will
 * not carry these headers and are correctly rejected here.
 */
class JwtAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->header('X-User-Id');

        if (! $userId) {
            return response()->json(['message' => 'Token not provided.', 'error' => 'token_missing'], 401);
        }

        $request->attributes->set('auth_user_id', $userId);
        $request->attributes->set('auth_user_email', $request->header('X-User-Email'));

        return $next($request);
    }
}
