<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtGatewayMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Token not provided.', 'error' => 'token_missing'], 401);
        }

        try {
            $secret  = config('jwt.secret');
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            // Forward user context as headers to downstream services
            $request->headers->set('X-User-Id',    $decoded->sub  ?? '');
            $request->headers->set('X-User-Email',  $decoded->email ?? '');
            $request->headers->set('X-User-Status', $decoded->status ?? '');

        } catch (\Firebase\JWT\ExpiredException) {
            return response()->json(['message' => 'Token expired.', 'error' => 'token_expired'], 401);
        } catch (\Exception) {
            return response()->json(['message' => 'Token invalid.', 'error' => 'token_invalid'], 401);
        }

        return $next($request);
    }
}
